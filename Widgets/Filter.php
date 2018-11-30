<?php
namespace exface\Core\Widgets;

use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Widgets\iCanBeRequired;
use exface\Core\Interfaces\Widgets\iHaveValue;
use exface\Core\Interfaces\Widgets\iTakeInput;
use exface\Core\Interfaces\Widgets\iShowSingleAttribute;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\CommonLogic\Model\Condition;
use exface\Core\Exceptions\Widgets\WidgetPropertyInvalidValueError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\BooleanDataType;

/**
 * A filter is a wrapper widget, which typically consist of one or more input widgets.
 * The purpose of filters is to enable the user to
 * input conditions.
 *
 * TODO Add an optional operator menu to the filter. That would be a drowdown populated with suitable comparison operators for the data
 * type of the value widget.
 * IDEA Should one filter also be able to create condition groups? Or should there be a FilterGroup widget?
 *
 * @author Andrej Kabachnik
 *        
 */
class Filter extends Container implements iTakeInput, iShowSingleAttribute
{

    private $widget = null;

    private $comparator = null;

    private $required = null;
    
    private $apply_on_change = false;

    /**
     * Returns the widget used to interact with the filter (typically some kind of input widget)
     *
     * @return iTakeInput
     */
    public function getInputWidget()
    {
        if (is_null($this->widget)) {
            $this->setInputWidget($this->getPage()->createWidget('Input', $this));
        }
        return $this->widget;
    }

    /**
     * Sets the widget used to interact with the filter (typically some kind of input widget)
     *
     * @param iTakeInput|UxonObject $widget_or_uxon_object            
     * @return \exface\Core\Widgets\Filter
     */
    public function setInputWidget($widget_or_uxon_object)
    {
        $page = $this->getPage();
        $this->widget = WidgetFactory::createFromAnything($page, $widget_or_uxon_object, $this);
        
        // Some widgets need to be transformed to be a meaningfull filter
        if ($this->widget->is('InputCheckBox')) {
            $this->widget = $this->widget->transformIntoSelect();
        }
        
        // Set a default comparator
        if (is_null($this->comparator)) {
            // If the input widget will produce multiple values, use the IN comparator
            if ($this->widget->implementsInterface('iSupportMultiselect') && $this->widget->getMultiSelect()) {
                $this->setComparator(EXF_COMPARATOR_IN);
            }
            // Otherwise leave the comparator null for other parts of the logic to use their defaults
        }
        
        // If the filter has a specific comparator, that is non-intuitive, add a corresponding suffix to
        // the caption of the actual widget.
        switch ($this->getComparator()) {
            case EXF_COMPARATOR_GREATER_THAN:
            case EXF_COMPARATOR_GREATER_THAN_OR_EQUALS:
            case EXF_COMPARATOR_LESS_THAN:
            case EXF_COMPARATOR_LESS_THAN_OR_EQUALS:
                $this->widget->setCaption($this->getInputWidget()->getCaption() . ' (' . $this->getComparator() . ')');
                break;
        }
        
        // The widgets in the filter should not be required accept for the case if the filter itself is marked
        // as required (see set_required()). This is important because, inputs based on required attributes are
        // marked required by default: this should not be the case for filters, however!
        if ($this->widget instanceof iCanBeRequired) {
            $this->widget->setRequired(false);
        }
        
        // Filters do not have default values, because they are empty if nothing has been entered. It is important
        // to tell the underlying widget to ignore defaults as it will use the default value of the meta attribute
        // otherwise. You can still set the value of the filter. This only prevents filling the value automatically
        // via the meta model defaults.
        if ($this->widget instanceof iHaveValue) {
            $this->widget->setIgnoreDefaultValue(true);
        }
        
        // The filter should be enabled all the time, except for the case, when it is diabled explicitly
        if (! parent::isDisabled()) {
            $this->setDisabled(false);
        }
        
        return $this;
    }

    /**
     *
     * @see \exface\Core\Widgets\Container::getChildren()
     */
    public function getChildren() : \Iterator
    {
        yield $this->getInputWidget();
    }

    /**
     *
     * @return MetaAttributeInterface
     */
    public function getAttribute()
    {
        return $this->getInputWidget()->getAttribute();
    }

    /**
     *
     * @return unknown
     */
    public function getAttributeAlias()
    {
        return $this->getInputWidget()->getAttributeAlias();
    }

    /**
     *
     * @return \exface\Core\Widgets\Filter
     */
    public function setAttributeAlias($value)
    {
        $this->getInputWidget()->setAttributeAlias($value);
        return $this;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\Widgets\AbstractWidget::getValue()
     */
    public function getValue()
    {
        return $this->getInputWidget()->getValue();
    }
    
    public function getValueWithDefaults()
    {
        return $this->getInputWidget()->getValueWithDefaults();
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\Widgets\AbstractWidget::getValueExpression()
     */
    public function getValueExpression()
    {
        return $this->getInputWidget()->getValueExpression();
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\Widgets\AbstractWidget::setValue()
     */
    public function setValue($value)
    {
        $this->getInputWidget()->setValue($value);
        return $this;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\Widgets\AbstractWidget::getCaption()
     */
    public function getCaption()
    {
        return $this->getInputWidget()->getCaption();
    }

    /**
     * Magic method to forward all calls to methods, not explicitly defined in the filter to ist value widget.
     * Thus, the filter is a simple proxy from the point of view of the template. However, it can be easily
     * enhanced with additional methods, that will override the ones of the value widget.
     * TODO this did not really work so far. Don't know why. As a work around, added some explicit proxy methods
     *
     * @param string $name            
     * @param array $arguments            
     */
    public function __call($name, $arguments)
    {
        $widget = $this->getInputWidget();
        return call_user_func_array(array(
            $widget,
            $name
        ), $arguments);
    }

    public function getComparator()
    {
        // IDEA give the comparator a default value. But make sure, setInputWidget() retains the possibility
        // to detect, that the comparator is not set and set one based on the input widget (or, perhaps even
        // better, move that logic here).
        return $this->comparator;
    }

    public function setComparator($value)
    {
        if (! $value){
            return $this;
        }
        try {
            $this->comparator = Condition::sanitizeComparator($value);
        } catch (UnexpectedValueException $e){
            throw new WidgetPropertyInvalidValueError($this, 'Invalid comparator "' . $value . '" used for filter widget!', '6W1SD52', $e);
        }
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iCanBeRequired::isRequired()
     */
    public function isRequired()
    {
        return $this->required ?? false;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iCanBeRequired::setRequired()
     */
    public function setRequired($value)
    {
        $value = BooleanDataType::cast($value);
        $this->required = $value;
        if ($this->getInputWidget() && $this->getInputWidget() instanceof iCanBeRequired) {
            $this->getInputWidget()->setRequired($value);
        }
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\Container::setDisabled()
     */
    public function setDisabled($value)
    {
        if ($this->getInputWidget()) {
            $this->getInputWidget()->setDisabled($value);
        }
        return parent::setDisabled($value);
    }

    public function exportUxonObject()
    {
        $uxon = parent::exportUxonObject();
        $uxon->setProperty('comparator', $this->getComparator());
        $uxon->setProperty('required', $this->isRequired());
        $uxon->setProperty('input_widget', $this->getInputWidget()->exportUxonObject());
        return $uxon;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iHaveValue::getValueDataType()
     */
    public function getValueDataType()
    {
        return $this->getInputWidget()->getValueDataType();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iHaveValue::getEmptyText()
     */
    public function getEmptyText()
    {
        return $this->getInputWidget()->getEmptyText();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iHaveValue::setEmptyText()
     */
    public function setEmptyText($value)
    {
        $this->getInputWidget()->setEmptyText($value);
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iShowSingleAttribute::hasAttributeReference()
     */
    public function hasAttributeReference()
    {
        return $this->getInputWidget()->hasAttributeReference();
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iHaveValue::hasValue()
     */
    public function hasValue()
    {
        return $this->getInputWidget()->hasValue();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\AbstractWidget::isPrefillable()
     */
    public function isPrefillable()
    {
        return parent::isPrefillable() && $this->getInputWidget()->isPrefillable();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\AbstractWidget::setDoNotPrefill()
     */
    public function setDoNotPrefill($value)
    {
        $this->getInputWidget()->setDoNotPrefill($value);
        return parent::setDoNotPrefill($value);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\AbstractWidget::getDoNotPrefill()
     */
    public function getDoNotPrefill()
    {
        return parent::getDoNotPrefill() || $this->getInputWidget()->getDoNotPrefill();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\AbstractWidget::isHidden()
     */
    public function isHidden()
    {
        return parent::isHidden() || $this->getInputWidget()->isHidden();
    }
    
    /**
     * 
     * @return bool
     */
    public function getApplyOnChange() : bool
    {
        return $this->apply_on_change;
    }
    
    /**
     * Set to TRUE to refresh the filterd widget automatically when the value of the filter changes.
     * 
     * FALSE by default.
     * 
     * @uxon-property apply_on_change
     * @uxon-type boolean
     * 
     * @param boolean $true_or_false
     * @return Filter
     */
    public function setApplyOnChange($true_or_false) : Filter
    {
        $this->apply_on_change = BooleanDataType::cast($true_or_false);
        return $this;
    }
    
    public function isDisplayOnly()
    {
        return $this->getInputWidget()->isDisplayOnly();
    }

    public function setReadonly($true_or_false)
    {
        $this->getInputWidget()->setReadonly($true_or_false);
        return $this;
    }

    public function isReadonly()
    {
        return $this->getInputWidget()->isReadonly();
    }

    public function setDisplayOnly($true_or_false)
    {
        $this->getInputWidget()->setDisplayOnly($true_or_false);
        return $this;
    }

}