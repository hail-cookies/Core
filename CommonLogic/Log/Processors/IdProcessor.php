<?php
namespace exface\Core\CommonLogic\Log\Processors;

/**
 * Moves the 'id' from context to extra.
 * 
 * @author Georg Bieger
 */
class IdProcessor extends AbstractContextProcessor
{
    const KEY_ID = 'id';

    /**
     * @inheritDoc
     */
    protected function invokeInternal(array &$context, array &$extra): void
    {
        $id = $this->extractValue($context, self::KEY_ID);
        $this->setValue($extra, self::KEY_ID, $id);
    }
}