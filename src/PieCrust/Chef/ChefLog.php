<?php

namespace PieCrust\Chef;

use PieCrust\PieCrustDefaults;


/**
 * A PEAR log with pretty colors, at least on Mac/Linux.
 */
class ChefLog extends \Log_console
{
    protected $color;

    public function __construct($name, $ident = '', $conf = array(), $level = PEAR_LOG_DEBUG)
    {
        parent::Log_console($name, $ident, $conf, $level);

        if (PieCrustDefaults::IS_WINDOWS())
            $this->color = null;
        else
            $this->color = new \Console_Color2();
    }

    public function log($message, $priority = null)
    {
        if ($this->color)
        {
            if ($priority === null)
                $priority = $this->_priority;

            $colorCode = false;
            switch ($priority)
            {
            case PEAR_LOG_EMERG:
            case PEAR_LOG_ALERT:
            case PEAR_LOG_CRIT:
                $colorCode = "%R";
                break;
            case PEAR_LOG_ERR:
                $colorCode = "%r";
                break;
            case PEAR_LOG_WARNING:
                $colorCode = "%y";
                break;
            case PEAR_LOG_DEBUG:
                $colorCode = "%K";
                break;
            }
            if ($colorCode)
            {
                $message = $this->color->convert(
                    $colorCode . 
                    $this->color->escape($message) .
                    "%n");
            }
        }

        return parent::log($message, $priority);
    }
}
