<?php

namespace OCA\NextMagentaCloudProvisioning\Logger;

use OCP\IConfig;
use OCP\Log\ILogFactory;
use Psr\Log\LoggerInterface;

class ProvisioningLogger
{
    private LoggerInterface $parentLogger;

    public function __construct(ILogFactory $logFactory, IConfig $config) {
        $default = $config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . '/nmc_provisioning.log';
        $logFile = $config->getSystemValue('logfile_nmc_provisioning', $default);
        $this->parentLogger = $logFactory->getCustomPsrLogger($logFile);
    }

    public function emergency($message, array $context = array()) {
        $this->parentLogger->emergency($message, $context);
    }

    public function alert($message, array $context = array()) {
        $this->parentLogger->alert($message, $context);
    }

    public function critical($message, array $context = array()) {
        $this->parentLogger->critical($message, $context);
    }

    public function error($message, array $context = array()) {
        $this->parentLogger->error($message, $context);
    }

    public function warning($message, array $context = array()) {
        $this->parentLogger->warning($message, $context);
    }

    public function notice($message, array $context = array()) {
        $this->parentLogger->notice($message, $context);
    }

    public function info($message, array $context = array()) {
        $this->parentLogger->info($message, $context);
    }

    public function debug($message, array $context = array()) {
        $this->parentLogger->debug($message, $context);
    }

    public function log($level, $message, array $context = array()) {
        $this->parentLogger->log($level, $message, $context);
    }
}
