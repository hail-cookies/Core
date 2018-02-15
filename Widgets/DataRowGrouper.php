<?php
namespace exface\Core\Widgets;

use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Exceptions\Widgets\WidgetConfigurationError;
use exface\Core\Exceptions\Widgets\WidgetNotFoundError;
use exface\Core\Exceptions\Widgets\WidgetLogicError;

/**
 * This widget is used to group rows in a data table.
 * 
 * The widget itself basically represents the group headers. It also defines,
 * how the rows are group: what is the grouping criteria, if and how sorting
 * is to be performed, etc.
 * 
 * Example:
 * {
 *  "widget_type": "DataTable",
 *  ...
 *  "row_grouper": {
 *      "group_by_attribute_alias": "MY_ATTRIBUTE",
 *      "expand": true,
 *      "show_count": true
 *  }
 * }
 * 
 * @method DataTable getParent()
 *
 * @author Andrej Kabachnik
 *        
 */
class DataRowGrouper extends AbstractWidget
{
    const EXPAND_ALL_GROUPS = 'ALL';
    const EXPAND_FIRST_GROUP = 'FIRST';
    const EXPAND_NO_GROUPS = 'NONE';
    
    /**
     * @var string|null
     */
    private $group_by_column_id = null;
    
    /**
     * 
     * @var string|null
     */
    private $group_by_attribute_alias = null;
    
    /**
     * 
     * @var DataColumn|null
     */
    private $group_by_column = null;
    
    /**
     * @var boolean
     */
    private $expand_groups = self::EXPAND_ALL_GROUPS;
    
    /**
     * 
     * @var boolean
     */
    private $show_counter = false;
    
    /**
     * 
     * @throws WidgetConfigurationError
     * @return DataTable
     */
    public function getDataTable()
    {
        $table = $this->getParent();
        if (! ($table instanceof DataTable)) {
            throw new WidgetConfigurationError($this, 'A DataRowGrouper cannot be used outside of a DataTable widget!', '6Z5MAVK');
        }
        return $table;
    }
    
    /**
     * 
     * @return string
     */
    protected function getGroupByColumnId()
    {
        if (is_null($this->group_by_column_id)) {
            return $this->getGroupByColumn()->getId();
        }
        return $this->group_by_column_id;
    }
    
    /**
     * Specifies an existing column for grouping - presuming the column has an explicit id.
     * 
     * Using column ids groups can be created over calculated columns. For columns with
     * attributes from the meta model, specifying the attribute_alias is simpler.
     * 
     * @uxon-property group_by_column_id
     * @uxon-type string
     * 
     * @param string $value
     * @return \exface\Core\Widgets\DataRowGrouper
     */
    public function setGroupByColumnId($value)
    {
        $this->group_by_column_id = $value;
        return $this;
    }
    
    /**
     * 
     * @throws WidgetNotFoundError
     * @throws WidgetLogicError
     * @throws WidgetConfigurationError
     * 
     * @return \exface\Core\Widgets\DataColumn
     */
    public function getGroupByColumn()
    {
        if (! is_null($this->group_by_attribute_alias)) {
            if (! is_null($this->group_by_column_id)) {
                throw new WidgetConfigurationError($this, 'Alternative properties "group_by_attribute_alias" and "group_by_column_id" are defined at the same time for a DataRowGrouper widget: please use only one of them!', '6Z5MAVK');
            }
            if (! $col = $this->getDataTable()->getColumnByAttributeAlias($this->group_by_attribute_alias)) {
                throw new WidgetLogicError('No data column "' . $this->group_by_attribute_alias . '" could be added automatically by the DataRowGrouper: try to add it manually to the DataTable.');
            }
        } elseif (! is_null($this->group_by_column_id)) {
            if (! $col = $this->getDataTable()->getColumn($this->group_by_column_id)) {
                throw new WidgetNotFoundError('Cannot find the column "' . $this->group_by_column_id . '" to group rows by!', '6Z5MAVK');
            }
        } else {
            throw new WidgetConfigurationError($this, 'No column to group by can be found for DataRowGrouper!', '6Z5MAVK');
        }
        $this->group_by_column = $col;
        
        return $this->group_by_column;
    }
    
    /**
     * 
     * @return boolean
     */
    public function getExpandAllGroups()
    {
        return $this->expand_groups === self::EXPAND_ALL_GROUPS;
    }
    
    /**
     * Set to FALSE to collapse all groups when loading data - TRUE by default.
     * 
     * @uxon-property expand_all_groups
     * @uxon-type boolean
     * 
     * @param boolean $true_or_false
     * @return \exface\Core\Widgets\DataRowGrouper
     */
    public function setExpandAllGroups($true_or_false)
    {
        $this->expand_groups = BooleanDataType::cast($true_or_false) ? self::EXPAND_ALL_GROUPS : self::EXPAND_NO_GROUPS;
        return $this;
    }
    
    /**
     *
     * @return boolean
     */
    public function getExpandFirstGroupOnly()
    {
        return $this->expand_groups === self::EXPAND_FIRST_GROUP;
    }
    
    /**
     * Set to FALSE to collapse all groups when loading data - TRUE by default.
     *
     * @uxon-property expand_all_groups
     * @uxon-type boolean
     *
     * @param boolean $true_or_false
     * @return \exface\Core\Widgets\DataRowGrouper
     */
    public function setExpandFirstGroupOnly($true_or_false)
    {
        $this->expand_groups = BooleanDataType::cast($true_or_false) ? self::EXPAND_FIRST_GROUP : self::EXPAND_ALL_GROUPS;
        return $this;
    }
    
    /**
     * 
     * @return boolean
     */
    public function getShowCounter()
    {
        return $this->show_counter;
    }
    
    /**
     * Set to TRUE to show the numer of grouped rows in each group header - FALSE by default.
     * 
     * @uxon-property show_counter
     * @uxon-type boolean
     * 
     * @param boolean $true_or_false
     * @return \exface\Core\Widgets\DataRowGrouper
     */
    public function setShowCounter($true_or_false)
    {
        $this->show_counter = BooleanDataType::cast($true_or_false);
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    protected function getGroupByAttributeAlias()
    {
        if (is_null($this->group_by_attribute_alias)) {
            return $this->getGroupByColumn()->getAttributeAlias();
        }
        return $this->group_by_attribute_alias;
    }
    
    /**
     * Specifies the attribute to group over - a corresponding (hidden) data column will be added automatically.
     * 
     * If there already is a column with this attribute alias, it will be used for grouping
     * instead of creating a new one.
     * 
     * @uxon-property group_by_attribute_alias
     * @uxon-type string
     * 
     * @param string $alias
     * @return \exface\Core\Widgets\DataRowGrouper
     */
    public function setGroupByAttributeAlias($alias)
    {
        $this->group_by_attribute_alias = $alias;
        if (! $col = $this->getDataTable()->getColumnByAttributeAlias($this->group_by_attribute_alias)) {
            $col = $this->getDataTable()->createColumnFromAttribute($this->getMetaObject()->getAttribute($this->group_by_attribute_alias), null, true);
            $this->getDataTable()->addColumn($col);
        }
        return $this;
    }
    
    /**
     * Since the DataRowGrouper basically represents the group header, now width can be set, as it is allways
     * as wide as the data table.
     * 
     * @see \exface\Core\Widgets\AbstractWidget::setWidth()
     */
    public function setWidth($value)
    {
        return $this;
    }
    
}
?>