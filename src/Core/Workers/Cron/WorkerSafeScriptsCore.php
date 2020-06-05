<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 4 2020
 */

namespace MikoPBX\Core\Workers\Cron;

require_once 'globals.php';

use Generator;
use MikoPBX\Core\System\{BeanstalkClient, Firewall, PBX, System, Util};
use MikoPBX\Core\Workers\WorkerAmiListener;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Core\Workers\WorkerCallEvents;
use MikoPBX\Core\Workers\WorkerCdr;
use MikoPBX\Core\Workers\WorkerLicenseChecker;
use MikoPBX\Core\Workers\WorkerLongPoolAPI;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Core\Workers\WorkerNotifyByEmail;
use MikoPBX\Core\Workers\WorkerNotifyError;
use MikoPBX\PBXCoreREST\Workers\WorkerApiCommands;
use Recoil\React\ReactKernel;

class WorkerSafeScriptsCore extends WorkerBase
{
    public const CHECK_BY_BEANSTALK = 'checkWorkerBeanstalk';

    public const CHECK_BY_AMI = 'checkWorkerAMI';

    public function start($argv): void
    {
        $this->waitFullyBooted();

        ReactKernel::start(
            function () {
                // Parallel execution https://github.com/recoilphp/recoil
                yield [
                    $this->checkWorkerBeanstalk(WorkerCdr::class),
                    $this->checkWorkerBeanstalk(WorkerModelsEvents::class),
                    $this->checkWorkerBeanstalk(WorkerCallEvents::class),
                    $this->checkWorkerBeanstalk(WorkerLicenseChecker::class),
                    $this->checkWorkerBeanstalk(WorkerNotifyByEmail::class),
                    $this->checkWorkerBeanstalk(WorkerNotifyError::class),
                    $this->checkWorkerBeanstalk(WorkerApiCommands::class),
                    $this->checkWorkerBeanstalk(WorkerLongPoolAPI::class),
                    $this->checkWorkerAMI(WorkerAmiListener::class), // Проверка листнера UserEvent
                ];
            }
        );

        // Modules workers
        $arrModulesWorkers = [];
        $pbxConfModules    = $this->di->getShared('pbxConfModules');
        foreach ($pbxConfModules as $pbxConfModule) {
            $arrModulesWorkers[] = $pbxConfModule->getModuleWorkers();
        }
        $arrModulesWorkers = array_merge(...$arrModulesWorkers);
        if (count($arrModulesWorkers) > 0) {
            ReactKernel::start(
                function () use ($arrModulesWorkers) {
                    // Parallel execution https://github.com/recoilphp/recoil
                    foreach ($arrModulesWorkers as $moduleWorker) {
                        if ($moduleWorker['type'] === self::CHECK_BY_AMI) {
                            yield $this->checkWorkerAMI($moduleWorker['worker']);
                        } else {
                            yield $this->checkWorkerBeanstalk($moduleWorker['worker']);
                        }
                    }
                }
            );
        }

        Firewall::checkFail2ban();
    }

    /**
     * Ожидаем полной загрузки asterisk.
     *
     * @return bool
     */
    private function waitFullyBooted(): bool
    {
        $time_start = microtime(true);
        $result     = false;
        $out        = [];
        if (Util::isSystemctl()) {
            $options = '';
        } else {
            $options = '-t';
        }

        while (true) {
            $execResult = Util::mwExec(
                "/usr/bin/timeout {$options} 1 /usr/sbin/asterisk -rx'core waitfullybooted'",
                $out
            );
            if ($execResult === 0 && implode('', $out) === 'Asterisk has fully booted.') {
                $result = true;
                break;
            }
            $time = microtime(true) - $time_start;
            if ($time > 60) {
                Util::sysLogMsg(__CLASS__, 'Error: Asterisk has not booted');
                break;
            }
        }

        return $result;
    }

    /**
     * Проверка работы сервиса через beanstalk.
     *
     * @param $workerClassName
     *
     * @return \Generator|null
     */
    public function checkWorkerBeanstalk($workerClassName): ?Generator
    {
        try {
            $WorkerPID = Util::getPidOfProcess($workerClassName);
            $result    = false;
            if ($WorkerPID !== '') {
                // We had service PID, so we will ping it
                $queue = new BeanstalkClient($this->makePingTubeName($workerClassName));
                // Check service with higher priority
                $result = $queue->request('ping', 15, 0);
            }
            if (false === $result) {
                Util::restartPHPWorker($workerClassName);
                Util::sysLogMsg(__CLASS__, "Service {$workerClassName} started.");
            }
        } catch (\Exception $e) {
            global $errorLogger;
            $errorLogger->captureException($e);
            Util::sysLogMsg($workerClassName . '_EXCEPTION', $e->getMessage());
        }
        yield;
    }


    /**
     * Check AMI listener
     *
     * @param $workerClassName - service name
     * @param $level           - recursion level
     *
     * @return \Generator|null
     */
    public function checkWorkerAMI($workerClassName, $level = 0): ?Generator
    {
        try {
            $res_ping  = false;
            $WorkerPID = Util::getPidOfProcess($workerClassName);
            if ($WorkerPID !== '') {
                // We had service PID, so we will ping it
                $am       = Util::getAstManager();
                $res_ping = $am->pingAMIListner($this->makePingTubeName($workerClassName));
                if (false === $res_ping) {
                    Util::sysLogMsg('checkWorkerAMI', 'Restart...');
                }
            }

            if ($res_ping === false && $level < 10) {
                Util::restartPHPWorker($workerClassName);
                Util::sysLogMsg(__CLASS__, "Service {$workerClassName} started.");
                // Wait 5 seconds while service will be ready to listen requests
                sleep(5);

                // Check service again
                $this->checkWorkerAMI($workerClassName, $level + 1);
            }
        } catch (\Exception $e) {
            global $errorLogger;
            $errorLogger->captureException($e);
            Util::sysLogMsg($workerClassName . '_EXCEPTION', $e->getMessage());
        }
        yield;
    }

}

// Start worker process
$workerClassname = WorkerSafeScriptsCore::class;
if (isset($argv) && count($argv) > 1 && $argv[1] === 'start') {
    cli_set_process_title($workerClassname);
    try {
        $worker = new $workerClassname();
        $worker->start($argv);
    } catch (\Exception $e) {
        global $errorLogger;
        $errorLogger->captureException($e);
        Util::sysLogMsg("{$workerClassname}_EXCEPTION", $e->getMessage());
    }
}