<?php

namespace exface\Core\CommonLogic\Log\Processors;

use exface\Core\CommonLogic\Log\CsvLogConfig;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Adds the username to the extra field of the log record.
 * 
 * @author Georg Bieger
 */
class UserNameProcessor extends AbstractContextProcessor
{
    const KEY_USER_NAME = CsvLogConfig::KEY_USER_NAME;

    private WorkbenchInterface $workbench;

    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }
    
    /**
     * @inheritDoc
     */
    protected function invokeInternal(array &$context, array &$extra) : void
    {
        $this->setValue(
            $extra,
            self::KEY_USER_NAME,
            $this->workbench->getSecurity()->getAuthenticatedToken()->getUsername()
        );
    }
}