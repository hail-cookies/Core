<?php
namespace exface\Core\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\CommonLogic\Utils\LazyHierarchicalDataCache;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\Model\BehaviorInterface;
use exface\Core\Interfaces\Events\DataSheetEventInterface;
use exface\Core\Events\Behavior\OnBeforeBehaviorAppliedEvent;
use exface\Core\Events\Behavior\OnBehaviorAppliedEvent;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Exceptions\Behaviors\BehaviorConfigurationError;
use exface\Core\CommonLogic\Debugger\LogBooks\BehaviorLogBook;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Events\DataSheet\OnBeforeCreateDataEvent;
use exface\Core\Events\DataSheet\OnBeforeUpdateDataEvent;
use exface\Core\Events\DataSheet\OnBeforeDeleteDataEvent;

/**
 * This behavior orders given indizes within a boundary
 *
 * The event is triggered before every create, update or delete operation.
 * It works by searching data in the data source for matches in every element of `indexing_boundary_attributes`
 * - multiple for complex tree structures - to find all neighboring elements.
 * These then will be ordered by the `order_index_attribute` - depending on the properties `close_gaps`
 * the indices will be incremented evenly, correcting all missing values. Duplicate values will always be corrected.
 *
 * By default close_gaps will occure.
 *
 * In addition you can change the `starting_index`, deciding which index should be the first to start
 * with and depending on `new_element_ontop` this will be relevant with every new entry.
 *
 * By default `new_element_ontop` will be set to false, preventing reordering all related elements within the same boundary.
 * CAUTION: Only elements with UIDs can make use of this configuration!
 *
 * ## Examples
 *
 * ### Order pages by it's position within their menu parent. Closing gaps and inserting new elements otop.
 *
 * Example config to order the menu postions of `exface.Core.PAGE`.
 *
 * ```
 *  {
 *      "indexing_boundary_attributes": [
 *          "MENU_PARENT"
 *      ],
 *      "close_gaps": true,
 *      "order_index_attribute": "MENU_POSITION",
 *      "new_element_ontop": false,
 *      "starting_index": 1
 *  }
 * 
 * ```
 *
 * @author Miriam Seitz
 *        
 */
class OrderingBehavior extends AbstractBehavior
{

    // internal variables
    private $working = false;

    private $indexSheets = [];

    // configurable variables
    private $startIndex = 1;
    private $closeGaps = true;
    private $insertNewOntop = false;
    private $indexAttributeAlias = null;
    private $boundaryAttributeAliases = [];

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::registerEventListeners()
     */
    protected function registerEventListeners(): BehaviorInterface
    {
        $handler = array($this, 'handleBeforeEvent');
        $prio = $this->getPriority();
        $this->getWorkbench()->eventManager()->addListener(OnBeforeCreateDataEvent::getEventName(), $handler, $prio);
        $this->getWorkbench()->eventManager()->addListener(OnBeforeUpdateDataEvent::getEventName(), $handler, $prio);
        $this->getWorkbench()->eventManager()->addListener(OnBeforeDeleteDataEvent::getEventName(), $handler, $prio);

        return $this;
    }

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::unregisterEventListeners()
     */
    protected function unregisterEventListeners(): BehaviorInterface
    {
    	$handler = array($this, 'handleBeforeEvent');
    	
    	$this->getWorkbench()->eventManager()->removeListener(OnBeforeCreateDataEvent::getEventName(), $handler);
    	$this->getWorkbench()->eventManager()->removeListener(OnBeforeUpdateDataEvent::getEventName(), $handler);
    	$this->getWorkbench()->eventManager()->removeListener(OnBeforeDeleteDataEvent::getEventName(), $handler);

        return $this;
    }

    /**
     *
     * @param DataSheetEventInterface $event
     */
    public function handleBeforeEvent(DataSheetEventInterface $event)
    {
    	if (!$event->getDataSheet()->getMetaObject()->isExactly($this->getObject())) {
    		return;
    	}

        $logbook = new BehaviorLogBook($this->getAlias(), $this, $event);
        $logbook->setIndentActive(1);
        
        if ($this->working === true) {
        	$logbook->addLine('Ordering not necessary. Element is already in the process of being ordered.');
        	return;
        }
        
        $sheet = $event->getDataSheet();        
        if ($sheet->hasUidColumn() === false) {
            $logbook->addLine('Cannot order objects with no Uid attribute.');
            return;
        }
        $logbook->addLine('Received ' . $sheet->countRows() . ' rows of ' . $sheet->getMetaObject()->__toString());
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeBehaviorAppliedEvent($this, $event, $logbook));
        $this->working = true;

        // Create cache.
        $cache = new LazyHierarchicalDataCache();
        // Fill missing index values.
        //$this->fillMissingIndices($sheet, $logbook); TODO update index fill
        // start working with potential update data that trigger this handler again
        $updateSheets = $this->orderDataByIndexingAttribute($sheet, $cache, $logbook);
        
        if (count($updateSheets) === 0) {
        	$logbook->addLine('No changes to order necessary.');
        } else {
        	$this->updateNecessaryChanges($event, $updateSheets, $logbook);
        }
        
        $this->working = false;
        $this->getWorkbench()->eventManager()->dispatch(new OnBehaviorAppliedEvent($this, $event, $logbook));
    }
    
	/**
	 * @param sheet
	 * @param LogBookInterface $logbook
	 * @return void
	 */
	 private function fillMissingIndices(
		DataSheetInterface $sheet,
		LogBookInterface $logbook) : void
	 {
	 	$insertOntop = $this->getNeedsInsertNewOntop();
        $uidAlias = $sheet->getUidColumnName();
        $indexAttributeAlias = $this->getIndexAttributeAlias();
        $rowIndex = 0;
        $changedRows = 0;
        foreach ($sheet->getRows() as $row) {
            // skip entries that already have an uid or a indexAttribute.
        	if (!empty($row[$uidAlias]) || !empty($row[$indexAttributeAlias]) ) {
                continue;
            }

            $newIndex = null;
            if($insertOntop) {
                $newIndex = $this->getStartIndex();
            } else {
                $indexSheet = $this->loadNeighboringElements($sheet, $row, $rowIndex, $indexAttributeAlias, $logbook);
                $newIndex = max($indexSheet->getColumnValues($indexAttributeAlias))+1;
            }

            $sheet->setCellValue($indexAttributeAlias, $rowIndex, $newIndex);
            $changedRows++;
            $rowIndex ++;
        }
        
        if ($changedRows > 0){
	        $logbook->addLine('Ordered ' . $changedRows . $insertOntop ? 'on top ' : 'at the end ' . 'of their boundary.');        	
        }
	}

    /**
     * Iterates each data rows of the event sheet and loads a new sheet with its neighbors as well.
     * This sheet will be sorted asc and ordered, all necessary changes will be held in an updateSheet.
     * Returns an array of updateSheets for each row of the given data sheet.
     *
     * @param DataSheetInterface $sheet
     * @param LogBookInterface $logbook
     * @return array
     */
    private function orderDataByIndexingAttribute(
    	DataSheetInterface $sheet,
        LazyHierarchicalDataCache $cache,
    	LogBookInterface $logbook): array
    {
        $updateSheets = [];
        $indexAttributeAlias = $this->getIndexAttributeAlias();
        foreach ($sheet->getRows() as $rowIndex => $row) {
            $uid = $row[$sheet->getUidColumnName()];
            if(!($siblingSheet = $cache->getData($uid))) {
                $siblingSheet = $this->getSiblings($sheet, $row, $indexAttributeAlias, $cache, $logbook);
            }

            $currentIndex = $row[$indexAttributeAlias];
            switch (true) {
                case $currentIndex === null:
                    throw new InvalidArgumentException("INVALID INDEX: This should have been prevented with index back fill!");
                    //$initValue = max($indexSheet->getColumns()->getByExpression($indexAttributeAlias)->getValues()) + 1;
                    //$sheet->setCellValue($indexAttributeAlias, $rowIndex, $initValue);
                    break;
            	case is_numeric($currentIndex):
            		$updateSheet = $this->findNecessaryChangesInSequence($row, $indexAttributeAlias, $siblingSheet, $logbook); //TODO should be deferred
            		
            		// if closeGap changed the current event value we need to update the sheet that will be saved TODO might be bad logic
            		if ($row[$indexAttributeAlias] != $currentIndex){
            		    $sheet->setCellValue($indexAttributeAlias, $rowIndex, $row[$indexAttributeAlias]);
            		}
            		
            		if (count($updateSheet->getRows()) > 0) {            			
	                    $updateSheets[] = $updateSheet;
            		}
                    break;
                default:
                    throw new BehaviorConfigurationError(
                    	$this, 
                    	'Cannot order values of attribute "' . $indexAttributeAlias . '": invalid value "' . $currentIndex . "' encountered! Ordering indices must be numeric.",
                    	$logbook);
                    break;
            }
        }

        return $updateSheets;
    }

    private function getSiblings(
        DataSheetInterface $sheet,
        array $row,
        string $indexAttributeAlias,
        LazyHierarchicalDataCache $cache,
        LogBookInterface $logbook): DataSheetInterface
    {
        $uidAlias = $sheet->getUidColumnName();
        $indexSheet = $this->createEmptyCopyWithIndexAttribute($sheet, $indexAttributeAlias);

        $parents = [];
        foreach ($this->getBoundaryAttributesAliases() as $boundaryAttributeAlias) {
            $parent = $row[$boundaryAttributeAlias];
            if(!in_array($parent, $parents)){
                $parents[] = $parent;
            }

            $indexSheet->getColumns()->addFromExpression($boundaryAttributeAlias);

            $indexSheet->getFilters()->addConditionFromString(
                $boundaryAttributeAlias,
                $parent,
                ComparatorDataType::EQUALS,
                false);
        }

        // Finalize sheet and load data.
        $indexSheet->getSorters()->addFromString($indexAttributeAlias, SortingDirectionsDataType::ASC);
        $indexSheet->dataRead();

        // Store data in cache.
        foreach ($indexSheet->getRows() as $indexedRow) {
            $cache->addElement($indexedRow[$uidAlias], $parents);
        }
        $cache->setData($row[$uidAlias], $indexSheet);

        $logbook->addLine(
            'Found '
            . $indexSheet->countRows()
            . ' neighboring elements for the ' . $row[$uidAlias] . ' event data object.');
        return $indexSheet;
    }



    /**
     * Iterates through all sorted neighboring elements to find unset or false indizes that need to be changed.
     * Returns all changes in one datasheet.
     *
     * @param array $row
     * @param string $indexAttributeAlias
     * @param DataSheetInterface $indexSheet
     * @param LogBookInterface $logbook
     * @return DataSheetInterface
     */
    private function findNecessaryChangesInSequence(
        array              &$row,
        string             $indexAttributeAlias,
        DataSheetInterface $indexSheet): DataSheetInterface
    {
        $updateSheet = $this->createEmptyCopyWithIndexAttribute($indexSheet, $indexAttributeAlias);
        $closeGaps = $this->getCloseGaps();

        // so currentIndex becomes startIndex if smaller or equal
        $lastIndex = $this->getStartIndex() - 1;
        foreach ($indexSheet->getRows() as $currentRow) {
            $currentIndex = $currentRow[$indexAttributeAlias];
            $updateNeeded = false;
            switch (true) {
            	// if same as new value, skip index to make room for new object
            	case ($currentIndex == $row[$indexAttributeAlias]):
            		$updateNeeded = true;
            		$currentIndex = $lastIndex+2;
            		break;
                // choose start index if first entry is null
                case ($currentIndex === null):
                    $updateNeeded = true;
                    $currentIndex = $lastIndex === null ? $lastIndex : $lastIndex + 1;
                    break;
                // correct duplicates and smaller values
                case ($currentIndex <= $lastIndex):
                // close gaps if requested
                case ($currentIndex > $lastIndex + 1 && $closeGaps):
                    $updateNeeded = true;
                    $currentIndex = $lastIndex + 1;
                    break;
            }

            if ($updateNeeded) {
                $currentRow[$indexAttributeAlias] = $currentIndex;
                $updateSheet->addRow($currentRow);
            }

            $lastIndex = $currentIndex;
        }

        // correct current value
        if ($lastIndex < $row[$indexAttributeAlias] && $closeGaps){
        	$row[$indexAttributeAlias] = $lastIndex + 1;
        }

        return $updateSheet;
    }

    /**
     *
     * @param DataSheetInterface $sheet
     * @param string $indexAttributeAlias
     * @return DataSheetInterface
     */
    // TODO Do we really need a dataSheet instance to store this information? (Maybe use array of rows instead).
    private function createEmptyCopyWithIndexAttribute(
    	DataSheetInterface $sheet, 
    	string $indexAttributeAlias): DataSheetInterface
    {
        $newSheet = DataSheetFactory::createFromObject($sheet->getMetaObject());
        $newSheet->getColumns()->addFromSystemAttributes();
        $newSheet->getColumns()->addFromExpression($indexAttributeAlias);
        return $newSheet;
    }

    /**
     *
     * @param DataSheetEventInterface $event
     * @param array $updateSheets
     * @param LogBookInterface $logbook
     * @return void
     */
    private function updateNecessaryChanges(
    	DataSheetEventInterface $event, 
    	array $updateSheets, 
    	LogBookInterface $logbook): void
    {
        $logbook->addLine('Updating ' . count($updateSheets) . ' elements.');
        foreach ($updateSheets as $updateSheet) {
            $updateSheet->dataUpdate(false, $event->getTransaction());
        }
    }

    /**
     * Set the default starting index.
     * This comes into effect if no element has an index yet
     * and when new_element_ontop is set to true.
     *
     * @uxon-property starting_index
     * @uxon-type integer
     * @uxon-default 0
     *
     * @param int $value
     * @return OrderingBehavior
     */
    protected function setStartingIndex(int $value): OrderingBehavior
    {
        $this->startIndex = $value;
        return $this;
    }

    /**
     *
     * @return int
     */
    protected function getStartIndex(): int
    {
        return $this->startIndex;
    }

    /**
     * Set to FALSE to disable reindexing when a new item is inserted
     *
     * @uxon-property close_gaps
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $trueOrFalse
     * @return OrderingBehavior
     */
    protected function setCloseGaps(bool $trueOrFalse): OrderingBehavior
    {
        $this->closeGaps = $trueOrFalse;
        return $this;
    }

    /**
     *
     * @return bool
     */
    protected function getCloseGaps(): bool
    {
        return $this->closeGaps;
    }

    /**
     * Set to FALSE if new elements without index should be inserted at the end
     *
     * @uxon-property new_element_ontop
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $trueOrFalse
     * @return OrderingBehavior
     */
    protected function setNewElementOntop(bool $trueOrFalse): OrderingBehavior
    {
        $this->insertNewOntop = $trueOrFalse;
        return $this;
    }

    /**
     *
     * @return bool
     */
    protected function getNeedsInsertNewOntop(): bool
    {
        return $this->insertNewOntop;
    }

    /**
     *
     * @return array
     */
    protected function getBoundaryAttributesAliases(): array
    {
        return $this->boundaryAttributeAliases;
    }

    /**
     * Aliases of attributes that define an index - e.g.
     * a folder to be indexed
     *
     * @uxon-property indexing_boundary_attributes
     * @uxon-type metamodel:attribute[]
     * @uxon-template [""]
     *
     * @param UxonObject $value
     * @return OrderingBehavior
     */
    protected function setIndexingBoundaryAttributes(UxonObject $value): OrderingBehavior
    {
        $this->boundaryAttributeAliases = $value->toArray();
        return $this;
    }

    /**
     *
     * @return string
     */
    protected function getIndexAttributeAlias(): string
    {
        return $this->indexAttributeAlias;
    }

    /**
     * Alias of index attribute to order
     *
     * @uxon-property order_index_attribute
     * @uxon-type metamodel:attribute
     * @uxon-required true
     *
     * @param string $value
     * @return OrderingBehavior
     */
    protected function setOrderIndexAttribute(string $value): OrderingBehavior
    {
        $this->indexAttributeAlias = $value;
        return $this;
    }
}