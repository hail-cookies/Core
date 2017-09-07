<?php
namespace exface\Core\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Factories\ActionFactory;
use exface\Core\CommonLogic\Model\ActionList;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Interfaces\Actions\iShowWidget;
use exface\Core\Interfaces\Actions\iRunTemplateScript;
use exface\Core\Interfaces\ActionListInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Widgets\WidgetPropertyInvalidValueError;

/**
 * This action chains other actions together and performs them one after another.
 *
 * All actions in the action-array will be performed one-after-another in the order of definition. Every action receives
 * the result data of it's predecessor as input. The first action will get the input from the chain action. The result
 * of the action chain is the result of the last action.
 * 
 * NOTE: actions showing widgets cannot be used in actions chains as the will not show anything!
 *
 * Here is a simple example:
 *  {
 *      "alias": "exface.Core.ActionChain",
 *      "actions": [
 *          {
 *              "alias": "my.app.CreateOrder",
 *              "name": "Order",
 *              "input_rows_min": 0
 *          },
 *          {
 *              "alias": "my.app.PrintOrder"
 *          }
 *      ]
 * }
 *
 * As a rule of thumb, the action chain will behave as the first action in the it: it will inherit it's name, input restrictions, etc.
 * Thus, in the above example, the action chain would inherit the name "Order" and "input_rows_min=0". However, there are some
 * important exceptions from this rule:
 * - the chain has modified data if at least one of the actions modified data
 *
 * By default, all actions in the chain will be performed in a single transaction. That is, all actions will get rolled back if at least
 * one failes. Set the property "use_single_transaction" to false to make every action in the chain run in it's own transaction.
 *
 * Action chains can be nested - an action in the chain can be another chain. This way, complex processes even with multiple transactions
 * can be modeled (e.g. the root chain could have use_single_transaction disabled, while nested chains would each have a wrapping transaction).
 *
 * @author Andrej Kabachnik
 *        
 */
class ActionChain extends AbstractAction
{

    private $actions = array();

    private $use_single_transaction = true;

    private $output = '';

    protected function init()
    {
        parent::init();
        $this->actions = new ActionList($this->getWorkbench(), $this);
    }

    protected function perform()
    {
        if ($this->getActions()->isEmpty()) {
            throw new ActionConfigurationError($this, 'An action chain must contain at least one action!', '6U5TRGK');
        }
        
        $result = null;
        $output = '';
        $data = $this->getInputDataSheet();
        foreach ($this->getActions() as $action) {
            // Prepare the action
            // All actions obviously run in the same template
            $action->setTemplateAlias($this->getTemplateAlias());
            // They are all called by the widget, that called the chain
            if ($this->getCalledByWidget()) {
                $action->setCalledByWidget($this->getCalledByWidget());
            }
            // If the chain should run in a single transaction, this transaction must be set for every action to run in
            if ($this->getUseSingleTransaction()) {
                $action->setTransaction($this->getTransaction());
            }
            // Every action gets the data resulting from the previous action as input data
            $action->setInputDataSheet($data);
            
            // Perform
            $data = $action->getResultDataSheet();
            $output = $action->getResultOutput();
            $result = $action->getResult();
            $this->addResultMessage($action->getResultMessage() . "\n");
            if ($action->isDataModified()) {
                $this->setDataModified(true);
            }
        }
        if ($data) {
            $this->setResultDataSheet($data);
        }
        $this->setResult($result);
        $this->output = $output;
    }

    /**
     *
     * @return ActionListInterface|ActionInterface[]
     */
    public function getActions()
    {
        return $this->actions;
    }

    public function setActions($uxon_array_or_action_list)
    {
        if ($uxon_array_or_action_list instanceof ActionListInterface) {
            $this->actions = $uxon_array_or_action_list;
        } elseif ($uxon_array_or_action_list instanceof UxonObject) {
            foreach ($uxon_array_or_action_list as $nr => $action_or_uxon) {
                if ($action_or_uxon instanceof UxonObject) {
                    $action = ActionFactory::createFromUxon($this->getWorkbench(), $action_or_uxon);
                } elseif ($action_or_uxon instanceof ActionInterface) {
                    $action = $action_or_uxon;
                } else {
                    throw new ActionConfigurationError($this, 'Invalid chain link of type "' . gettype($action_or_uxon) . '" in action chain on position ' . $nr . ': only actions or corresponding UXON objects can be used as!', '6U5TRGK');
                }
                $this->addAction($action);
            }
        } else {
            throw new WidgetPropertyInvalidValueError('Cannot set actions for ' . $this->getAliasWithNamespace() . ': invalid format ' . gettype($uxon_array_or_action_list) . ' given instead of and instantiated condition or its UXON description.');
        }
        
        return $this;
    }

    public function addAction(ActionInterface $action)
    {
        if ($action instanceof iShowWidget){
            throw new ActionConfigurationError($this, 'Actions showing widgets cannot be used within action chains!');
        }
        
        if ($action instanceof iRunTemplateScript){
            throw new ActionConfigurationError($this, 'Actions running template scripts cannot be used within action chains!');
        }
        
        $this->getActions()->add($action);
        return $this;
    }

    public function getUseSingleTransaction()
    {
        return $this->use_single_transaction;
    }

    public function setUseSingleTransaction($value)
    {
        $this->use_single_transaction = \exface\Core\DataTypes\BooleanDataType::parse($value);
        return $this;
    }

    public function getResultOutput()
    {
        return $this->output;
    }

    public function getInputRowsMin()
    {
        return $this->getActions()->getFirst()->getInputRowsMin();
    }

    public function getInputRowsMax()
    {
        return $this->getActions()->getFirst()->getInputRowsMax();
    }

    public function isUndoable()
    {
        return false;
    }

    public function getName()
    {
        if (! parent::hasName()) {
            return $this->getActions()->getFirst()->getName();
        }
        return parent::getName();
    }

    public function getIconName()
    {
        return parent::getIconName() ? parent::getIconName() : $this->getActions()->getFirst()->getIconName();
    }
    
    public function implementsInterface($interface)
    {
        if ($this->getActions()->isEmpty()){
            return parent::implementsInterface($interface);
        }
        return $this->getActions()->getFirst()->implementsInterface($interface);
    }
    
    /**
     * For every method not exlicitly inherited from AbstractAciton attemt to call it on the first action.
     * 
     * @param mixed $method
     * @param mixed $arguments
     * @return mixed
     */
    public function __call($method, $arguments){
        return call_user_func_array(array($this->getActions()->getFirst(), $method), $arguments);
    }
     
}
?>