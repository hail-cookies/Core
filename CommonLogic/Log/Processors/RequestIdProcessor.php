<?php

namespace exface\Core\CommonLogic\Log\Processors;


use exface\Core\CommonLogic\Workbench;

/**
 * Adds the requestId to the extra field of the log record.
 * 
 * @author Georg Bieger
 */
class RequestIdProcessor extends AbstractContextProcessor
{
    const KEY_REQUEST_ID = 'requestId';

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