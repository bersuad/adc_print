<?php

/**
 * \file    lib/AdcLogger.php
 * \ingroup adceinvoice
 * \brief   Centralized logging service for the ADC eInvoicing module.
 */

/**
 * Class AdcLogger
 * Provides a standardized way to log events in the ADC eInvoicing module.
 */
class AdcLogger
{
    /**
     * Log an error message.
     *
     * @param string $message The message to log.
     * @param array $context Optional contextual data.
     * @return void
     */
    public static function error($message, array $context = [])
    {
        self::log(LOG_ERR, $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message The message to log.
     * @param array $context Optional contextual data.
     * @return void
     */
    public static function warning($message, array $context = [])
    {
        self::log(LOG_WARNING, $message, $context);
    }

    /**
     * Log an informational message.
     *
     * @param string $message The message to log.
     * @param array $context Optional contextual data.
     * @return void
     */
    public static function info($message, array $context = [])
    {
        self::log(LOG_INFO, $message, $context);
    }

    /**
     * Log a debug message.
     *
     * @param string $message The message to log.
     * @param array $context Optional contextual data.
     * @return void
     */
    public static function debug($message, array $context = [])
    {
        self::log(LOG_DEBUG, $message, $context);
    }

    /**
     * Internal logging method that wraps dol_syslog.
     *
     * @param int $level Dolibarr log level constant.
     * @param string $message The message to log.
     * @param array $context Additional context data.
     * @return void
     */
    private static function log($level, $message, array $context = [])
    {
        $contextString = empty($context) ? '' : ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        dol_syslog('modAdcEinvoice: ' . $message . $contextString, $level);
    }
}
