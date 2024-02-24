<?php
namespace CopyResources\Form;

use Laminas\Form\Form;
use Omeka\Mvc\Exception\RuntimeException;

class CopyConfirmForm extends Form
{
    public function init()
    {
        // Note that we can add resource-specific form elements with the passed
        // "resourceName" option, using `$this->getOption('resourceName')`.
        $this->add([
            'type' => 'submit',
            'name' => 'submit',
            'attributes' => [
                'value' => 'Confirm copy', // @translate
                'style' => 'background-color: #676767; border-color: #676767; color: #fff;',
            ],
        ]);
    }
}
