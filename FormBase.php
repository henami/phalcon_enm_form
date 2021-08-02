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

*/


namespace { // global

use Phalcon\Forms\Form;
use Phalcon\Filter\FilterFactory;

class FormBase extends Form
{
    protected $_version  = "2.1";
    protected $separator = PHP_EOL;
    protected $indent    = "    "; // space x 4

    protected $confirm   = false;

    /* default classes for form elements */
    protected $_default_element_class = 'form-control col-sm-6 mr-1';
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
    protected $_default_required_style = 'color:red;font-size:xx-small';


    /* properties */ 
    protected $form_params;
    protected $required_style;

    public function __construct ($entity = null, $userOptions = array())
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
            $action = $this->router->getControllerName()  . '/' . $this->router->getActionName();
            foreach($this->router->getParams() as $param ){
                $action = $action . '/' . $param;
            }
            $this->setAction($action);
        }

        // Form properties from config files
        $this->form_params = $this->config->form->toArray() ?? [];

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
        $output .= $this->tag->endForm() . $separator;

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
        $output_attr = isset($options['attributes']) ? $options['attributes'] : array();
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
        $output_required = '';
        if ($element->getAttribute('required',false) or $element->getUserOption('required',false) ) {
            if (! $this->required_style) {
                $this->required_style = $this->_default_required_style;
            }
            $output_required = '<span style="' . $this->required_style .'">*</span>';
            $element->setLabel($element->getLabel() . $output_required);
        }
        $output    = $element->label($output_attr);
        $placement = array_key_exists('placement', $options) ? $options['placement'] : 'APPEND';
        return $this->_concatenate($output, $content, $placement);
    }
    private function _buildInput($element, $options = array(), $content = '')
    {
        $output_attr = $element->getAttributes();
        $raw_value = array_key_exists('raw_value', $options) ? TRUE : FALSE;
        // for Select field for special purpose(radio or multi checked check box and so)
        $is_radio    = $element->getUserOption('is_radio',    FALSE);
        $is_checkbox = $element->getUserOption('is_checkbox', FALSE);

        if (is_array($element->getUserOption('multi_values'))){
            $theValue = implode(',',$element->getUserOption('multi_values',array()));
        } else {
            $theValue = $element->getValue();
        }
        #$filtered =  (new Phalcon\Filter())->sanitize($theValue, 'string');
        $factory = new FilterFactory();
        $locator = $factory->newInstance();
        $filtered =  $locator->sanitize($theValue, "string");


        if ($raw_value) {
            $output    = $filtered;
        } else {
            if (!$this->confirm) {
                $is_radio    = $element->getUserOption('is_radio',    FALSE);
                $is_checkbox = $element->getUserOption('is_checkbox', FALSE);
                if (get_class($element) == 'Phalcon\Forms\Element\Select') {
                    if ($is_radio) {
                        // render as radio button
                        $output = $this->_renderAsRadio($element,$output_attr,$content);
                    } elseif ($is_checkbox) {
                        // render as check box
                        $output = $this->_renderAsCheckbox($element,$output_attr,$content);
                    } else {
                        // render as select box
                        $output  = $element->render($output_attr);
                    }
                } else {
                    $output  = $element->render($output_attr);
                }
            } else {
                switch ( get_class($element)) {
                case 'Phalcon\Forms\Element\Hidden' :
                case 'Phalcon\Forms\Element\Submit' :
                    $output  = $element->render($output_attr);
                    break;
                case 'Phalcon\Forms\Element\Password' :
                    $output  = '<span class="form-control-static">' . '********' . '</span>';
                    $output .= ' <input type="hidden" name="' . $element->getName() . '" value="' . $filtered . '"/>';
                    break;
                case 'Phalcon\Forms\Element\TextArea' :
                    #$output  = nl2br($element->getValue());
                    $output  = '<span class="form-control-static">' . nl2br($filtered) . '</span>';
                    $output .= ' <input type="hidden" name="' . $element->getName() . '" value="' . $filtered . '"/>';
                    break;
                case 'Phalcon\Forms\Element\Select' :
                    #$output  = '<span class="form-control-static">' . $filtered . '</span>';
                    $opts  = $element->getOptions();
                    $opts_class = is_array($opts) ? 'array' : get_class($opts);
                    if ($opts_class == "Phalcon\Mvc\Model\Resultset\Simple") {
                        // if using tables
                        $opts_using  = $element->getAttribute('using');
                        $opts_tmp    = Array();
                        foreach($opts as $r) {
                            $key = $r->{$opts_using[0]};
                            $val = $r->{$opts_using[1]};
                            $opts_tmp[$key] = $val;
                        }
                        $opts = $opts_tmp;
                    }
                    $valueArray = explode(',', $filtered);
                    $dispArray  = Array();
                    foreach ($valueArray as $v) {
                        #$dispArray[] = $opts[$v];
                        if (strlen($v) != 0) {
                            $dispArray[] = $opts[$v];
                        }
                    }
                    $disp = implode(',', $dispArray);
                    $output  = '<span class="form-control-static">' . $disp . '</span>';

                    if (preg_match('/\[\]$/', $element->getName())) { 
                        $hiddenName = preg_replace('/\[\]$/', '', $element->getName());
                    } else {
                        $hiddenName = $element->getName();
                    }
                    $output .= ' <input type="hidden" name="' . $hiddenName . '" value="' . $filtered . '"/>';
                    break;
                default:
                    #$output  = $element->getValue();
                    #$output  = '<p class="form-control-static">' . $filtered . '</p>';
                    $output  = '<span class="form-control-static">' . $filtered . '</span>';
                    if (preg_match('/\[\]$/', $element->getName())) { 
                        $hiddenName = preg_replace('/\[\]$/', '', $element->getName());
                    } else {
                        $hiddenName = $element->getName();
                    }
                    #$output .= ' <input type="hidden" name="' . $element->getName() . '" value="' . $filtered . '"/>';
                    $output .= ' <input type="hidden" name="' . $hiddenName . '" value="' . $filtered . '"/>';
                    break;
                }
            }
        }
        $placement = array_key_exists('placement', $options) ? $options['placement'] : 'APPEND';
        return $this->_concatenate($output, $content, $placement);
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
            #return $this->_concatenate($opentag, $content, 'PREPEND');
            return $this->_concatenate($opentag, $content, $placement);
        }
        if ( array_key_exists('closeOnly', $options) ) {
            #return $this->_concatenate($closetag, $content, 'APPEND');
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
            $content .= '<span class="alert-danger rounded pt-2 col-sm-3">';
            foreach ($messages as $message) {
                $content .=  $message;
            }
            $content .= '</span>';
        }
        return $content;
    }
    private function _buildDefaultDecorator()
    {
        $default_label_class = $this->getDefaultLabelClass();

        $this->_default_decorator = [
            'ViewHelper',
            'Errors',
            array('Label',  array('placement' => 'PREPEND', 'attributes' => array('class'=>$default_label_class))),
            array('HtmlTag',array('tag' => 'div', 'attributes' => array('class' => 'form-group row'))),
        ];
        $this->_default_confirm_decorator = [
            'ViewHelper',
            'Errors',
            array('Label',  array('placement' => 'PREPEND', 'attributes' => array('class'=>$default_label_class))),
            array('HtmlTag',array('tag' => 'div', 'attributes' => array('class' => 'form-group row'))),
        ];
    }
    public function getConfirm()
    {
        if ($this->confirm) {
            return TRUE;
        } else {
            return FALSE;
        }
    }
    public function setConfirm(bool $confirmMode = false)
    {
        if ($confirmMode) {
            $this->confirm = TRUE;
        } else {
            $this->confirm = FALSE;
        }
    }
    public function getRequiredStyle()
    {
        return $this->required_style ?? $this->_default_required_style;
    }
    public function setRequiredStyle($style = null)
    {
        if (! is_null($newClass)) {
            $this->required_style = $style;
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
    private function _renderAsRadio($element, $output_attr, $content = '')
    {
        // Render select box element as "Radio Button"
        $opts = $element->getOptions();
        $value = $element->getValue();

        $elm_options = '';
        foreach($output_attr as $k => $v) {
            $elm_options .= "$k=\"$v\" ";
        } 

        foreach ($opts as $op_value => $op_lable) {
            $content .= '<label>' . PHP_EOL;
            $content .= '  <input type="radio" name="' . $element->getName() . '" value="' . $op_value . '"';
            if ($op_value == $value) {
                $content .= ' checked="checked" ';
            }
            $content .= $elm_options;
            $content .= '>' . $op_lable; // . '<br />';
            $content .= PHP_EOL . '</label>' . PHP_EOL;
        }
        return $content;
    }
    private function _renderAsCheckbox($element, $output_attr, $content = '')
    {
        // Render multiple select box element as multiple "Checkbox"
        $opts  = $element->getOptions();
        $opts_class = get_class($opts);
        if ($opts_class == "Phalcon\Mvc\Model\Resultset\Simple") {
            // if using tables
            $opts_using  = $element->getAttribute('using');
            $opts_tmp    = Array();
            foreach($opts as $r) {
                $key = $r->{$opts_using[0]};
                $val = $r->{$opts_using[1]};
                $opts_tmp[$key] = $val;
            }
            $opts = $opts_tmp;
        }
        #$value = $element->getValue();
        $value = $element->getUserOption('multi_values', array());
        if (! is_array($value)) {
            $value = array($value);
        }
#var_dump($value);
        $elm_options = '';
        foreach($output_attr as $k => $v) {
            // "class" is only applied ... 
            if ( $k != 'class') {
                continue;
            }
            if ( ! is_array($v) ) {
                // -> ommit arrays like "using" parameters
                $elm_options .= "$k=\"$v\" ";
            }
        } 

        $content .= '<div class="col-sm-6 pl-0">' . PHP_EOL;

        foreach ($opts as $op_value => $op_lable) {
            $content .= '<div class="form-check form-check-inline">' . PHP_EOL;
            $content .= '<label class="form-check-label">' . PHP_EOL;
            $content .= '  <input type="checkbox" name="' . $element->getName() . '" value="' . $op_value . '" ';
            $content .= $elm_options;
            #if ($op_value == $value) {
            if (in_array($op_value, $value)) {
                $content .= ' checked="checked" ';
                #$elm = $this->get('data1[]');
                #$content .= ' ' . var_dump($element->getOptions()) . ' ';
            }
            $content .= '>';
            #$content .= '<label class="form-check-label">' . PHP_EOL;
            $content .= $op_lable; // . '<br />';
            $content .= PHP_EOL . '</label>' . PHP_EOL;
            $content .= '</div>';
        }

        $content .= PHP_EOL . '</div>' . PHP_EOL;
        return $content;
    }

}

} // namespace (global)

/**
 ** フォームエレメントで足りないものを新規に追加する。
 */

namespace Application\Forms\Element {


use Phalcon\Forms\Element\AbstractElement;
use Phalcon\Forms\Element\Select;

class Button extends AbstractElement
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
        $attrs = array();

        if (!is_null($attributes)) {
            foreach ($attributes as $attrName => $attrVal) {
                if (is_numeric($attrName) || in_array($attrName, array('id', 'name', 'placeholder', 'type'))) {
                    continue;
                }

                $attrs[] = $attrName .'="'. $attrVal .'"';
            }
        }

        $attrs = ' '. implode(' ', $attrs);

        $id      = $this->getAttribute('id', $this->getName());
        #$name    = $this->getName();
        $name    = 'action'; // fixed value! 
        $value   = $this->getValue();
        $label   = $this->getLabel() ? $this->getLabel() : $this->getValue();

        $type    = isset($attributes['type']) ? $attributes['type'] : 'submit';
        if (strtolower($type) == 'reset') {
            return <<<HTML
<button type="reset" value="{$value}"{$attrs}>{$label}</button>
HTML;
        } else {
            return <<<HTML
<button type="submit" name="{$name}" value="{$value}"{$attrs}>{$label}</button>
HTML;
        }
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
        $attrs = array();

        if (!is_null($attributes)) {
            foreach ($attributes as $attrName => $attrVal) {
                if (is_numeric($attrName) || in_array($attrName, array('id', 'name', 'placeholder'))) {
                    continue;
                }

                $attrs[] = $attrName .'="'. $attrVal .'"';
            }
        }

        $attrs = ' '. implode(' ', $attrs);

        $name    = $this->getName();
        $id      = $this->getAttribute('id', $name);

        $checked = '';
        if ($this->getValue()) {
            $checked = ' checked="checked"';
        }

        return <<<HTML
<input type="hidden" id="{$id}_" name="{$name}" value="0" />
<input type="checkbox" id="{$id}" name="{$name}" value="1"{$attrs}{$checked} />
HTML;
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
    public function __construct ($name, $options = null, $attributes = null) {
        parent::__construct($name, $options, $attributes);
    }

    public function getOptions()
    {
        $opts = parent::getOptions();

        $opts_class = get_class($opts[0]);
        if ($opts_class == "Phalcon\Mvc\Model\Resultset\Simple") {
            // if using tables
            $opts_using  = $opts[1]['using'];
            $opts_tmp    = [];
            foreach($opts[0] as $r) {
                $key = $r->{$opts_using[0]};
                $val = $r->{$opts_using[1]};
                $opts_tmp[$key] = $val;
            }
            $opts = $opts_tmp;
        }

        return $opts;
    }

    public function render($attributes = null):string
    {
        parent::render($attributes = null);
    }
}

// Radio Button based on select box
class Radio extends baseSelect
{
    public function render($attributes = null):string
    {
        // Render select box element as "Radio Button"
        $opts  = $this->getOptions();
        $value = $this->getValue();
        #
        $content = '';

        $elm_options = '';
        foreach($attributes as $k => $v) {
            $elm_options .= "$k=\"$v\" ";
        }

        $content .= '<div class="d-flex align-items-center justify-content-center">' . PHP_EOL;

        foreach ($opts as $op_value => $op_lable) {
            $content .= '<div class="form-check">' .PHP_EOL;
            $content .= '  <input type="radio" name="' . $this->getName() . '" value="' . $op_value . '"';
            if ($op_value == $value) {
                $content .= ' checked="checked" ';
            }
            $content .= $elm_options;
            $content .= '>' . PHP_EOL;
            $content .= '  <label>' . $op_lable . '</label>' . PHP_EOL;
            $content .= '</div>' . PHP_EOL;


            #$content .= '<label>';
            #$content .= '<input type="radio" name="' . $this->getName() . '" value="' . $op_value . '"';
            #if ($op_value == $value) {
            #    $content .= ' checked="checked" ';
            #}
            #$content .= $elm_options;
            #$content .= '>' . $op_lable;
            #$content .= '</label>' . PHP_EOL;
        }

        $content .= '</div>' . PHP_EOL;

        return $content;
    }
}

// Multi Checkboxes based on select box
class MultiCheck extends baseSelect
{
    public function __construct ($name, $options = null, $attributes = null) {
        $attributes['multiple'] = true;
        parent::__construct($name, $options, $attributes);
    }

    public function render($attributes = null):string
    {
        // Render multiple select box element as multiple "Checkbox"
        $opts  = $this->getOptions();

        $value = $this->getValue();
        $name = $this->getName() . '[]'; # this means this is "multiple select"

        #$content .= '<div>' . PHP_EOL;
        $content .= '<div class="d-flex align-items-center justify-content-center">' . PHP_EOL;

        foreach ($opts as $op_value => $op_lable) {
            $content .= '<div class="form-check form-check-inline">' . PHP_EOL;
            $content .= '  <input class="form-check-input" type="checkbox" name="' . $name . '" value="' . $op_value . '" ';
            if (in_array($op_value, $value)) {
                $content .= ' checked="checked" ';
            }
            $content .= '>' . PHP_EOL;;
            $content .= '  <label class="form-check-label">' . $op_lable . '</label>' . PHP_EOL;
            $content .= '</div>' . PHP_EOL;;
        }

        $content .= PHP_EOL . '</div>' . PHP_EOL;

        return $content;
    }
}



} // namespace (Application\Forms\Element)
