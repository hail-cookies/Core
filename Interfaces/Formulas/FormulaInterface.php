<?php
namespace exface\Core\Interfaces\Formulas;

use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\DataSheets\DataColumnInterface;
use exface\Core\Interfaces\Selectors\FormulaSelectorInterface;
use exface\Core\Exceptions\RuntimeException;

interface FormulaInterface extends WorkbenchDependantInterface
{
    /**
     * Evaluates the function based on a given data sheet and the coordinates
     * of a cell (data functions are only applicable to specific cells!)
     * This method is called for every row of a data sheet, while the function
     * is mostly defined for an entire column, so we try to do as little as possible
     * here: instantiate a class implementing FormulaExpressionLanguage and call the evaluate
     * method with the formula and teh current row as arguments.
     *
     * @param \exface\Core\Interfaces\DataSheets\DataSheetInterface $data_sheet            
     * @param string $column_name            
     * @param int $row_number            
     * @return mixed
     */
    public function evaluate(DataSheetInterface $data_sheet, int $row_number);

    /**
     * Returns the data type, that the formula will produce
     *
     * @return DataTypeInterface
     */
    public function getDataType();
    
    /**
     * Returns TRUE if the formula can be evaluated without a data sheet (e.g. NOW()) and FALSE otherwise.
     *
     * @return bool
     */
    public function isStatic() : bool;
    
    /**
     * 
     * @return FormulaSelectorInterface
     */
    public function getSelector() : FormulaSelectorInterface;
    
    /**
     * Returns attribute aliases of required attributes
     * 
     * @param bool $withRelationPath
     * @return array
     */
    public function getRequiredAttributes(bool $withRelationPath = true) : array;
    
    /**
     * Get the formula name. If no name can be found, throw exception.
     * 
     * @throws RuntimeException
     * @return string
     */
    public function getFormulaName() : string;
    
    /**
     * Get formula names of formulas that are nested in this formula
     * 
     * @return array
     */
    public function getNestedFormulas() : array;
    
    /**
     * Get the expression used to instiantiate this formula
     * 
     * @return string
     */
    public function __toString() : string;   
    
    /**
     * 
     * @return bool
     */
    public function hasRelationPath() : bool;
    
    /**
     *
     * @return string|NULL
     */
    public function getRelationPathString() : ?string;
    
    /**
     *
     * @param string $relationPath
     * @return FormulaInterface
     */
    public function withRelationPath(string $relationPath) : FormulaInterface;
}