<?php

/*

Form base class for Phalcon Web Framework



1.0  2018.01.20 H.Enami

- initial release

1.1  2018.08.10 H.Enami

- add manage functions for default decorations
- add using db table function for select fields
- add variables for default form class 

1.2  2019.09.09 H.Enami

- add original form elements
- add setter and getter function for $_default_{element,label}_class
- add using default form decorator in render_all() function

1.3  2020.12.18 H.Enami

- modify indent width on original form element
- add ":string" as type of return value on render()
- modify sanitize part for PHP7 and phalcon4

2.0  2020.12.23 H.Enami

- whole refactoring for phalcon4

2.1  2021.07.29 H.Enami

- refactoring again
- enabled form section in config.ini
- rename "render_block" to "renderDecorated"
- convert array() and Array() to []

2.2 2021.11.05 H.Enami

- use Bootstrap5 ( change some class name )
- add getDefaultDecorators() and setDefaultDecorators()
- add variables for required element rendering
- delete 'create="1"' from <form> tag : create is needed only for form class not for rendering
- add Application\Forms\Element\multiSelect Element
- add Application\Forms\Element\buttonGroup Element

2.3 2022.08.08 H.Enami

- modify for phalcon5

*/


namespace { // global

use Phalcon\Forms\Form;
use Phalcon\Filter\FilterFactory;
use Phalcon\Html\Helper\Label;
use Phalcon\Html\Escaper;

class FormBase extends Form
{
    protected $_version  = "2.3";
    protected $separator = PHP_EOL;
    protected $indent    = "    "; // space x 4

    protected $_confirm   = false;

    /* default classes for form elements */
    protected $_default_element_class = 'form-control col-sm-7 mr-1';
    /* default classes for form elements label */
    protected $_default_label_class   = 'col-form-label col-sm-2';
    /* default decorators for normal input */
    protected $_default_decorator = [];
    /* default decorators for confirm screen */
    protected $_default_confirm_decorator = [];
    /* default form properties */
    protected $_default_form_params = [
        "autocomplete" => "off",
        "enctype"      => "application/x-www-form-urlencoded", #"multipart/form-data",
        "method"       => 'post',
    ];
    #protected $_default_required_style = 'color:red;font-size:xx-small';
    protected $_default_required_style = 'color:red;';
    protected $_default_required_string = '*';


    /* properties */ 
    protected $form_params;
    protected $required_style;
    protected $required_string;

    public function __construct ($entity = null, $userOptions = [])
    {
        parent::__construct($entity, $userOptions);
    }

    public function render_all()
    {
        $separator = $this->separator;

        // Rebuild default decorator (normal and confirm)
        $this->_buildDefaultDecorator();

        // Rebuild Action if not specified
        $action = $this->getAction();
        if (! $action ){
            $action = $this->url->get('/') . $this->router->getControllerName()  . '/' . $this->router->getActionName();
            foreach($this->router->getParams() as $param ){
                $action = $action . '/' . $param;
            }
            $this->setAction($action);
        }

        // Form properties from config files
        $this->form_params = $this->config->form ? $this->config->form->toArray() : [];

        $output = '';

        // <form> : open tag
        $formOptions = array_merge(
            $this->_default_form_params,   // -> Default settings
            $this->form_params,            // -> Settings from config
            $this->getUserOptions(),       // -> Override
        );
        $formOptions['action'] = $action;
        if ($formOptions['method'] !== 'post') {
            unset($formOptions['enctype']);
        }
        unset($formOptions['create']); // "create" is not for <form> tag.
        $output .= $this->tag->form($formOptions);
        $output .= $separator . $separator;

        # traverse all elements
        $content_all ='';
        $content     ='';
        foreach ($this->getElements() as $element) {
            $content = $this->renderDecorated($element);
            // add separator for every content
            $content_all .= $content . $separator . $separator;
        }
        $output .= $content_all . $separator;
        // </form> : close tag
        $output .= $this->tag->close('form') . $separator;

        return $output;
    }

    public function renderDecorated($element = null )
    {
        $elm        = is_object($element) ? $element : $this->get($element);
        $decorators = $this->getDecorators($element);

        $content    = '';
        foreach($decorators as $decorator) {
            if ( is_array($decorator) ) {
                $type    = array_shift($decorator);
                $options = array_shift($decorator);
                if ( is_Null($options)) {
                    $options = array();
                }
            } else {
                $type    = $decorator;
                $options = array();
            }
            switch ($type){
              case 'Label':
                    $content = $this->_buildLabel($element, $options, $content);
                    break;
              case 'ViewHelper':
                    $content = $this->_buildInput($element, $options, $content);
                    break;
              case 'HtmlTag':
                    $content = $this->_wrapContent($element, $options, $content);
                    break;
              case 'Errors':
                    $content = $this->_buildError($element, $options, $content);
                    break;
              default:
                $content = $content . '<p>' . $type . '</p>';
            }
        }
        return $content;
    }

#
    public function getDecorators($element)
    {
        $decorator_name = 'decorators';
        if ($this->getConfirm()) {
            $decorator_name = 'decorators_confirm';
            return $element->getUserOption($decorator_name, $this->_default_confirm_decorator);
        }

        return $element->getUserOption($decorator_name, $this->_default_decorator);

    }
    public function getDefaultDecorators($decoratorName = "decorators"){
        if ($decoratorName == 'decorators_confirm') {
            return $this->_default_confirm_decorator;
        }

        return $this->_default_decorator;
    }
    public function setDefaultDecorators($decoratorName, array $decorators) {
        if ($decoratorName == 'decorators') {
            $this->_default_decorator = $decorators;
            return;
        }
        if ($decoratorName == 'decorators_confirm') {
            $this->_default_confirm_decorator = $decorators;
            return;
        }

        throw new Exception('The decorator name should be "decorators" or "decorators_confirm".');

    }

    private function _concatenate($output, $content, $placement = 'APPEND')
    {
        $separator = $this->separator;

        switch ($placement) {
        case 'PREPEND':
            return $output . $separator . $content;
        case 'APPEND':
        default:
            #return $content . $separator . $output;
            if (empty($content)) {
                return $output;
            }
            return $content . $separator . $output;
        }
    }

    private function _buildLabel($element, $options = array(), $content = '')
    {
        $output_attr = isset($options['attributes']) ? $options['attributes'] : [];
        switch ( get_class($element)) {
            case 'Phalcon\Forms\Element\Check' :
            case 'Phalcon\Forms\Element\Radio' :
                if ( array_key_exists('placement', $options) &&
                     $options['placement'] == 'WRAP' ) {
                    $label_opt=$options;
                    $label_opt['tag'] = 'label';
                    $content .= $element->getLabel();
                    return $this->_wrapContent($element, $label_opt, $content);
                }
                break;
        }
        if ($element->getAttribute('required',false) or $element->getUserOption('required',false) ) {
            $element->setLabel(
                $this->_buildRequired(
                    $element->getLabel()
                )
            );
        }
        #$output    = $element->label($output_attr);
        $escaper   = new Escaper();
        $label     = new Label($escaper); #this->_buildRequired($element->getLabel()));
        $raw       = TRUE;
        $output    = $label($element->getLabel(),$output_attr,$raw);
        $placement = array_key_exists('placement', $options) ? $options['placement'] : 'APPEND';
        return $this->_concatenate($output, $content, $placement);
    }
    private function _buildRequired($label = '')
    {
        $output_required = '<span style="' . $this->getRequiredStyle() .'">' . $this->getRequiredString() . '</span>';
        return $this->_concatenate($output_required, $label, "APPEND");

    }
    private function _buildInput($element, $options = array(), $content = '')
    {
        // Create form element for normal input
        if (!$this->getConfirm()) {
            $output = $this->_buildInputNormal($element, $options, $content);
            $placement = array_key_exists('placement', $options) ? $options['placement'] : 'APPEND';
            return $this->_concatenate($output, $content, $placement);
        }

        // Create form element for "confirmation"
        $output = $this->_buildInputConfirm($element, $options, $content);
        $placement = array_key_exists('placement', $options) ? $options['placement'] : 'APPEND';
        return $this->_concatenate($output, $content, $placement);
    }

    private function _buildInputNormal($element, $options = array(), $content = '')
    {
        $attr = $element->getAttributes();
        #$attr ??= [];
        if (! isset($attr['class'])) {
            $attr['class'] = $this->getDefaultElementClass();
        }

        $output  = $element->render($attr);

        return $output;
    }
    private function _buildInputConfirm($element, $options = array(), $content = '')
    {
        $attr = $element->getAttributes();
        if (! isset($attr['class'])) {
            $attr['class'] = $this->getDefaultElementClass();
        }

        // Get class name of the element
        $elementClass = get_class($element);

        if ($elementClass == 'Phalcon\Forms\Element\Hidden'){
            // Same rendering as input
            return $element->render($attr);
        }

        if ($elementClass == 'Phalcon\Forms\Element\Submit' ||
            $elementClass == 'Application\Forms\Element\Button' ||
            $elementClass == 'Application\Forms\Element\buttonGroup'){
            // Same rendering as input
            return $element->render($attr);
        };

        // Get minimal filtered value
        $theValue = $element->getValue();
        $factory  = new FilterFactory();
        $locator  = $factory->newInstance();
        $filtered = '';
        if (gettype($theValue) == 'array') {
            $filtered = implode(',', $theValue);
        } else {
            $filtered =  $locator->sanitize($theValue, "string");
        }

        if ($elementClass == 'Phalcon\Forms\Element\TextArea'){
            // Render LF/CR as <br/>
            $output  = '<span>' . nl2br($filtered) . '</span>';
            $output .= $this->tag->inputHidden(
                $element->getName(),
                $filtered,
            );
            return $output;
        }
 
        if ($elementClass == 'Application\Forms\Element\Check'){
            $str = $filtered ? '1 (ON)' : '0 (OFF)';
            #$output  = '<span>' . $str  . '</span>';
            $output  = $str;
            $output .= $this->tag->inputHidden(
                $element->getName(),
                $filtered,
            );
            return $output;
        }
 
        if ($elementClass == 'Phalcon\Forms\Element\Password'){
            $output  = '<span class="form-control-plaintext">' . '********' . '</span>';
            $output .= $this->tag->inputHidden(
                $element->getName(),
                $filtered,
            );
            return $output;
        }

        if ($elementClass == 'Phalcon\Forms\Element\Select' ||
            $elementClass == 'Application\Forms\Element\baseSelect' ||
            $elementClass == 'Application\Forms\Element\multiSelect' ||
            $elementClass == 'Application\Forms\Element\multiCheck' ||
            $elementClass == 'Application\Forms\Element\Radio'){
            $output = '';
            $options  = $element->getOptions();

            $valueArray = explode(',', $filtered);
            $dispArray  = Array();
            foreach ($valueArray as $v) {
                if (strlen($v) != 0) {
                    $dispArray[] = $options[$v];
                }
            }
            $disp = implode(',', $dispArray);
            $output  .= '<span>' . $disp . '</span>';

            // Omit '[]' from end of element name
            if (preg_match('/\[\]$/', $element->getName())) { 
                $hiddenName = preg_replace('/\[\]$/', '', $element->getName());
            } else {
                $hiddenName = $element->getName();
            }
            $output .= $this->tag->inputHidden(
                $hiddenName,
                $filtered,
            );
            return $output;
        }

        // Rendering Text element and any other elements not listed above.
        $output  = '<span>' . $filtered . '</span>';
        $output .= $this->tag->inputHidden(
            $element->getName(),
            $filtered,
        );

        return $output;
    }

    private function _wrapContent($element, $options = array(), $content = '')
    {
        $output_attr = isset($options['attributes']) ? $options['attributes'] : array();
        $placement = array_key_exists('placement', $options) ? $options['placement'] : 'APPEND';
        if ($options['tag'] == 'br') {
            return $this->_concatenate("<". $options['tag'] . " />", $content, $placement);
        }
        $attr='';
        foreach ($output_attr as $k => $v){
            $attr .= "$k=\"$v\" ";
        }
        $opentag   = '<' . $options['tag'] . ' ' . $attr . '>';
        $closetag  = '</' . $options['tag'] .'>';
        $separator = $this->separator;
        if ( array_key_exists('openOnly', $options) ) {
            return $this->_concatenate($opentag, $content, $placement);
        }
        if ( array_key_exists('closeOnly', $options) ) {
            return $this->_concatenate($closetag, $content, $placement);
        }

        return $this->_concatenate(
            $opentag,
            $this->_concatenate($closetag, $content, 'APPEND'),
            'PREPEND'
        );
    }
    private function _buildError($element, $options = array(), $content = '')
    {
        // Get any generated messages for the current element
        $messages = array();
        if ( $element->hasMessages() ) {
            $messages = $element->getMessages();
        }
        if (count($messages)) {
            $content .= '<span class="alert alert-danger rounded py-1 m-0 col-sm-3">';
            foreach ($messages as $message) {
                $content .=  $message;
            }
            $content .= '</span>';
        }
        return $content;
    }
    private function _buildDefaultDecorator()
    {
        # Note (for Bootstrap5)
        # - .form-group class was obsoleted. So change to 'mb-3' 

        $default_label_class = $this->getDefaultLabelClass();

        $this->_default_decorator = $this->_default_decorator ? $this->_default_decorator : [
            'ViewHelper',
            array('HtmlTag',array('tag' => 'div', 'attributes' => array('class' => 'col-sm-6'))),
            'Errors',
            array('Label',  array('placement' => 'PREPEND', 'attributes' => array('class'=>$default_label_class))),
            array('HtmlTag',array('tag' => 'div', 'attributes' => array('class' => 'row mb-3'))),
        ];
        $this->_default_confirm_decorator = $this->_default_confirm_decorator ? $this->_default_confirm_decorator :[
            'ViewHelper',
            array('HtmlTag',array('tag' => 'div', 'attributes' => array('class' => 'col-sm-6'))),
            'Errors',
            array('Label',  array('placement' => 'PREPEND', 'attributes' => array('class'=>$default_label_class))),
            array('HtmlTag',array('tag' => 'div', 'attributes' => array('class' => 'row mb-3'))),
        ];
    }
    public function getConfirm()
    {
        if ($this->_confirm) {
            return TRUE;
        } else {
            return FALSE;
        }
    }
    public function setConfirm(bool $confirmMode = false)
    {
        if ($confirmMode) {
            $this->_confirm = TRUE;
        } else {
            $this->_confirm = FALSE;
        }
    }
    public function getRequiredStyle()
    {
        return $this->required_style ?? $this->_default_required_style;
    }
    public function setRequiredStyle($style = null)
    {
        if (! is_null($style)) {
            $this->required_style = $style;
        }
    }
    public function getRequiredString()
    {
        return $this->required_string ?? $this->_default_required_string;
    }
    public function setRequiredString($string = null)
    {
        if (! is_null($string)) {
            $this->required_string = $string;
        }
    }
    public function getDefaultElementClass()
    {
        return $this->_default_element_class;
    }
    public function setDefaultElementClass($newClass = null)
    {
        if (! is_null($newClass)) {
            $this->_default_element_class = $newClass;
        }
    }
    public function getDefaultLabelClass()
    {
        return $this->_default_label_class;
    }
    public function setDefaultLabelClass($newClass = null)
    {
        if (! is_null($newClass)) {
            $this->_default_label_class = $newClass;
        }
    }

} // end of class

} // namespace (global)

/**
 ** フォームエレメントで足りないものを新規に追加する。
 */

namespace Application\Forms\Element {


use Phalcon\Forms\Element\AbstractElement;
use Phalcon\Forms\Element\ElementInterface;
use Phalcon\Forms\Element\Select;

class Button extends AbstractElement
{
    protected $class_for_button = "btn";
    /**
     * Renders the element widget returning html
     *
     * @param array|null $attributes Element attributes
     *
     * @return string
     */
    public function render($attributes = null):string
    {
        $content = '';

        # Gather some infomation
        $value = $this->getValue();
        $label = $this->getLabel() ?? $value;
        $name  = $this->getName();
        $id    = $this->getAttribute('id', $name);
        $type  = $this->getAttribute('type', 'submit');
        $class = $this->getAttribute('class');
        $class = trim("$this->class_for_button $class");
        # Ignore any other attributes....

        # Overwrite
        $name  = 'action'; # -> fixed value!

        $content .= '<button'
                 .  ' type="'  . $type  . '"'
                 .  ' name="'  . $name  . '"'
                 .  ' value="' . $value .'"'
                 .  ' id="'    . $id    . '"'
                 .  ' class="' . $class . '"'
                 .  '>'
        ;
        $content .= $label;
        $content .= '</button>' . PHP_EOL;
        return $content;

    }
}

class buttonGroup extends AbstractElement
{
    protected $buttons = [];

    public function __construct ($name, $attributes = []) {
        parent::__construct($name, $attributes);

        // Overwrite label strings to white space.
        if (! $this->getLabel() ) {
            $this->setLabel('&nbsp;');
        }
    }
    /**
     * Return button info with the specific name
     */
    public function get($name)
    {
        foreach($this->buttons as $button) {
            $btnName = $button->getName() ?? 'NotSet';
            return $button;
        }
        # Retrun null if not found
        return null;
    }
    /**
     * Add button info with the specific name
     */
    public function add($btnObj)
    {
        $this->buttons[] = $btnObj;

        return $this;
    }
    /**
     * Remove button info with the specific name
     */
    public function remove($name)
    {
        $i = 0;
        foreach($this->buttons as $button) {
            $bName = $button->getName();
            if ($name == $bName) {
                array_splice($this->buttons, $i, 1);
                return $this;
            }
            $i++;
        }
        // Do nothing...
        return $this;
    }

    /**
     * Renders the group of buttuns 
     *
     * @params array|null $attributes Element attributes
     *
     * @return string
     */
    public function render($attributes = null):string
    {
        $content = '';

        $content .= '<div class="d-flex"'
                 .  '>';
        $content .= PHP_EOL;

        foreach($this->buttons as $button) {
            #$content .= '<div class="col">';
            $content .= $button->render();
            #$content .= '</div>';
        }

        $content .= '</div>';
        $content .= PHP_EOL;

        return $content;
    }
}

class Check extends AbstractElement
{
    /**
     * Renders the element widget returning html
     *
     * @param array|null $attributes Element attributes
     *
     * @return string
     */
    public function render($attributes = null):string
    {
         $attrs = [];

         $name    = $this->getName();
         $id      = $this->getAttribute('id', $name);
         $class   = $this->getAttribute('class', '');
         $class   = 'form-check-input' . $class;
         
         $output = '';
         $output .= \Phalcon\Tag::hiddenField([
             'name'  => $name,
             'value' => '0',
             'id'    => $id . '_0',
         ]);
         $output .= PHP_EOL;
         $attr = [
             'name'  => $name,
             'value' => '1',
             'id'    => $id . '_1',
             'class' => $class,
         ];
         if ($this->getValue() | $this->getDefault()) {
             $attr['checked'] = "checked";
         }

         $output .= \Phalcon\Tag::checkField($attr);

         return $output;

   }
}


// Dummy text form ( only return plain text)
class Raw extends AbstractElement
{
    public function render($attributes = null):string
    {
        $value = $this->getValue();
        return <<<HTML
{$value}
HTML;
    }
}

// Select box
class baseSelect extends Select
{
    public function __construct ($name, $attributes = []) {
        parent::__construct($name, $attributes);
    }

    public function addOption($option) :ElementInterface
    {
        $type = gettype($option);
        if ($type == 'object'){
            #echo get_class($option);
            if (get_class($option) == 'Phalcon\Mvc\Model\Row'){
                $using = $this->getAttribute('using');
                $option = [
                    $option->{$using[0]} => $option->{$using[1]},
                ];
            }
        }
        parent::addOption($option);
        return $this;
    }

    public function setOptions($options) :ElementInterface
    {
        $type = gettype($options);
        if ($type == 'object'){
            #echo get_class($options);
            if (get_class($options) == 'Phalcon\Mvc\Model\Resultset\Simple'){
                $using = $this->getAttribute('using');
                $optionsArray = [];
                foreach($options as $option) {
                    $optionsArray[ $option->{$using[0]} ] = $option->{$using[1]};
                }
                $options = $optionsArray;
            }
        }
        parent::setOptions($options);
        return $this;
    }

    public function render($attributes = null):string
    {
        return parent::render($attributes);
    }
}

// Radio Button based on select box
class Radio extends baseSelect
{
    protected $indent          = '  ';
    # Classes for bootstrap5
    protected $class_for_radio       = 'form-check-input';
    protected $class_for_radio_label = 'form-check-label';
    protected $class_for_wrap        = 'form-check form-check-inline';

    public function render($attributes = null):string
    {
        $content = '';

        # Gather some infomation
        $opts  = $this->getOptions();
        $value = $this->getValue();
        $name  = $this->getName();
        $class = $this->getAttribute('class');
        $class = trim("$class $this->class_for_radio");
        # Ignore any other attributes....

        foreach ($opts as $op_value => $op_label) {

            $output  = '<div class="' . $this->class_for_wrap . '">' . PHP_EOL;
            $output .= $this->indent;

            $id = $name . '_' . $op_value;
            $params = [
                'name'  => $name,
                'id'    => $id,
                'value' => $op_value,
                'class' => $class,
            ];

            $checked = '';
            if ($value == $op_value ){
                $params['checked'] = 'checked';
            };

            $output .= \Phalcon\Tag::radioField($params);

            $output   .= PHP_EOL;
            $output   .= $this->indent
                      . '<label class="' . $this->class_for_radio_label . '"'
                      . ' for="' . $id .'"'
                      . '>';
            $output   .= $op_label;
            $output   .= '</label>'
                      . PHP_EOL;
            $output   .= '</div>' . PHP_EOL;

            $content  .= $output;
        }

        return $content;
    }
}

// Multi Checkboxes based on select box
class multiSelect extends baseSelect
{
    protected $indent             = '  ';
    protected $size_default       = 4;
    # Classes for bootstrap5
    protected $class_for_select   = 'form-select';

    public function render($attributes = null):string
    {
        // Render multiple select box element
        $content = '';

        # Gather some infomation
        $opts  = $this->getOptions();
        $value = $this->getValue();
        $name  = $this->getName() . '[]'; # [] means this element is "multiple select"
        $id    = $this->getAttribute('id') ?? $name;
        $size  = $this->getAttribute('size',$this->size_default);
        $class = $this->getAttribute('class');
        $class = trim("$class $this->class_for_select");
        # Ignore any other attributes....

        if (gettype($value) == 'array') {
            $valueArray = $value;
        } else {
            $valueArray = explode(',', $value);
        }

        // Dummy input for no options are selected
        $content .= \Phalcon\Tag::hiddenField([
            $this->getName(), // Need to omit '[]'
            'value' => ''
        ]);
        $content .= PHP_EOL;

        $content .= '<select'
                 . ' id="' . $id . '"'
                 . ' name="' . $name . '"'
                 . ' class="' . $class . '"'
                 . ' size="' . $size . '"'
                 . ' multiple="multiple"'
                 . ' />'
                 . PHP_EOL;

        foreach ($opts as $op_value => $op_label) {
            $content .= $this->indent
                     . '<option ' 
                     . ' value="' . $op_value . '"'
            ;
            if (in_array($op_value, $valueArray) ){
                $content .= ' selected="selected"';
            };
            $content .= ' />';
            $content .= $op_label;
            $content .= '</option>'
                     . PHP_EOL;
        }

        $content .= '</select>';

        return $content;

    }
}

// Multi Checkboxes based on select box
class multiCheck extends baseSelect
{
    protected $indent             = '  ';
    # Classes for bootstrap5
    protected $class_for_check    = 'form-check-input';
    protected $class_for_label    = 'form-check-label';
    protected $class_for_wrap     = 'form-check form-check-inline';

    public function render($attributes = null):string
    {
        // Render multiple select box element as multiple "Checkbox"
        $content = '';

        # Gather some infomation
        $opts  = $this->getOptions();
        $value = $this->getValue();
        $name  = $this->getName() . '[]'; # [] means this element is "multiple select"
        $id    = $this->getAttribute('id') ?? $name;
        $class = $this->getAttribute('class');
        $class = trim("$class $this->class_for_check");
        # Ignore any other attributes....

        if (gettype($value) == 'array') {
            $valueArray = $value;
        } else {
            $valueArray = explode(',', $value);
        }

        // Dummy input for no options are selected
        $content .= \Phalcon\Tag::hiddenField([
            $this->getName(), // Need to omit '[]'
            'value' => ''
        ]);
        $content .= PHP_EOL;


        foreach ($opts as $op_value => $op_lable) {

            $content .= '<div'
                     . ' class="' . $this->class_for_wrap . '"'
                     . ' />'
                     . PHP_EOL;

            $content .= $this->indent
                     . '<input'
                     . ' type="checkbox"'
                     . ' class="' . $this->class_for_check . '"'
                     . ' name="' . $name . '"'
                     . ' value="' . $op_value . '" '
                     ;
            if (in_array($op_value, $valueArray)) {
                $content .= ' checked="checked" ';
            }
            $content .= '>' . PHP_EOL;

            $content .= $this->indent
                     . '<label'
                     . ' class="' . $this->class_for_label
                     . '">'
                     ;
            $content .= $op_lable;
            $content .= '</label>'
                     . PHP_EOL;
            $content .= '</div>'
                     . PHP_EOL;

        }

        return $content;
    }
}



} // namespace (Application\Forms\Element)
