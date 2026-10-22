<?php

namespace exface\Core\CommonLogic\Log\Processors;


use exface\core\CommonLogic\Log\CsvLogConfig;
use exface\Core\CommonLogic\Workbench;

/**
 * Adds the requestId to the extra field of the log record.
 * 
 * @author Georg Bieger
 */
class RequestIdProcessor extends AbstractContextProcessor
{
    const KEY_REQUEST_ID = CsvLogConfig::KEY_REQUEST_ID;

    private Workbench $workbench;

    public function __construct(Workbench $workbench)
    {
        $this->workbench = $workbench;
    }
    
    /**
     * @inheritDoc
     */
    protected function invokeInternal(array &$context, array &$extra): void
    {
        $this->setValue(
            $extra,
            self::KEY_REQUEST_ID,
            $this->workbench->getContext()->getScopeRequest()->getScopeId()
        );
    }
}