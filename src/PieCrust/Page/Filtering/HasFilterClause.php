<?php

namespace PieCrust\Page\Filtering;

use PieCrust\IPage;


/**
 * Filter clause for having a specific setting value.
 */
class HasFilterClause extends FilterClause
{
    protected $coerceFunc;

    public function __construct($settingName, $settingValue, $settingCoerceFunc = null)
    {
        FilterClause::__construct($settingName, $settingValue);
        $this->coerceFunc = $settingCoerceFunc;
    }
    
    public function postMatches(IPage $post)
    {
        $actualValue = $post->getConfig()->getValue($this->settingName);
        if ($this->coerceFunc != null)
        {
            $coerceFunc = $this->coerceFunc;
            $actualValue = $coerceFunc($actualValue);
        }
        return $actualValue != null && 
            is_array($actualValue) &&
            in_array($this->settingValue, $actualValue);
    }
}
