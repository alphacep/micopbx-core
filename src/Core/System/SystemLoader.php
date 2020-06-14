<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 6 2020
 */

namespace MikoPBX\Core\System;

use MikoPBX\Core\System\Upgrade\UpdateDatabase;
use MikoPBX\Core\System\Upgrade\UpdateSystemConfig;
use MikoPBX\Service\Main; // at mikopbx.so
use Phalcon\Di;

class SystemLoader
{
    private $di;

    public function __construct()
    {
        $this->di = Di::getDefault();
    }

    /**
     * Load system services
     */
    public function startSystem(): bool
    {
        $this->di->getRegistry()->booting = true;
        $storage                          = new Storage();
        Util::echoWithSyslog(' - Mount storage disk... ');
        $storage->saveFstab();
        $storage->configure();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Start syslogd daemon...');
        $system = new System();
        $system->syslogDaemonStart();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Update database ... '. PHP_EOL);
        $dbUpdater = new UpdateDatabase();
        $dbUpdater->updateDatabaseStructure();

        Util::echoWithSyslog(' - Update configs and applications ... '. PHP_EOL);
        $confUpdate = new UpdateSystemConfig();
        $confUpdate->updateConfigs();


        Util::echoWithSyslog(' - Load kernel modules ... ');
        $system->loadKernelModules();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring VM tools ... ');
        $system->vmwareToolsConfigure();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring timezone ... ');
        $sys = new DateTime();
        $sys->timezoneConfigure();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring hostname ... ');
        $system->hostnameConfigure();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring resolv.conf ... ');
        $network = new Network();
        $network->resolvConfGenerate();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring network loopback interface ... ');
        $network->loConfigure();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring LAN interface ... ');
        $network->lanConfigure();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring SSH console ... ');
        $system->sshdConfigure();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring msmtp services... ');
        $notifications = new Notifications();
        $notifications->configure();
        Util::echoGreenDone();

        $this->di->getRegistry()->booting = false;

        return true;
    }

    /**
     * Load Asterisk and Web interface
     *
     * @return bool
     */
    public function startMikoPBX(): bool
    {
        $this->di->getRegistry()->booting = true;
        $system                           = new System();
        $pbx                              = new PBX();

        Util::echoWithSyslog(' - Start nats queue daemon...');
        $system->gnatsStart();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Start Nginx daemon...');
        $system->nginxGenerateConf();
        $system->nginxStart();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring Asterisk...'.PHP_EOL);
        $pbx->configure();

        Util::echoWithSyslog(' - Start Asterisk... ');
        $pbx->start();
        $system->onAfterPbxStarted();
        Util::echoGreenDone();

        Util::echoWithSyslog(' - Configuring Cron tasks... ');
        $system->cronConfigure();
        Util::echoGreenDone();

        $this->di->getRegistry()->booting = false;

        return true;
    }
}