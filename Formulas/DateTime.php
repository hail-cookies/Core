<?php
namespace exface\Core\Formulas;

use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Factories\DataTypeFactory;

/**
 * Parses (almost) any value into a date and time in the internal format or a given ICU format.
 *
 * The first parameter is the value to parse, while the second (optional) parameter is
 * the ICU date format.
 *
 * Examples:
 *
 * - `=DateTime('25.03.2020 21:00:55')` = 2020-03-25 21:00:55
 * - `=DateTime('1585090800')` = 2020-03-25 00:00:00
 * - `=DateTime('1585090800', 'dd.MM.yyyy HH:mm:ss)` = 25.03.2020 00:00:00
 * - `=DateTime('2020-03-25 21:00:55', 'yyyyMMddHHmmss')` = 20200325210055
 * 
 * See http://userguide.icu-project.org/formatparse/datetime for a complete guide to
 * the ICU date format syntax.
 *
 * @link http://userguide.icu-project.org/formatparse/datetime
 */
class DateTime extends Date
{
    /**
     * 
     * @param string $date
     * @param string $format
     */
    public function run($date = null, $format = DateTimeDataType::DATETIME_ICU_FORMAT_INTERNAL)
    {
        return parent::run($date, $format);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Formula::getDataType()
     */
    public function getDataType()
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), DateTimeDataType::class);
    }
}
?>