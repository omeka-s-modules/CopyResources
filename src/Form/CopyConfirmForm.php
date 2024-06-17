<?php
namespace CopyResources\Form;

use Laminas\Form\Form;

class CopyConfirmForm extends Form
{
    public function init()
    {
        // Note that we can add resource-specific form elements with the passed
        // "resourceName" option, using `$this->getOption('resourceName')`.
        $this->add([
            'type' => 'select',
            'name' => 'visibility',
            'options' => [
                'label' => 'Visibility',
                'empty_option' => 'Same as original', // @translate
                'value_options' => [
                    'public' => 'Public', // @translate
                    'private' => 'Private', // @translate
                ],
            ],
        ]);
        $this->add([
            'type' => 'submit',
            'name' => 'submit',
            'attributes' => [
                'value' => 'Confirm copy', // @translate
                'style' => 'background-color: #676767; border-color: #676767; color: #fff;',
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'visibility',
            'required' => false,
        ]);
    }
}
