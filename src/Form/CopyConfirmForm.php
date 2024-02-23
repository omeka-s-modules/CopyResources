<?php
namespace CopyResources\Form;

use Laminas\Form\Form;
use Omeka\Mvc\Exception\RuntimeException;

class CopyConfirmForm extends Form
{
    public function init()
    {
        // Add resource-specific form elements.
        switch ($this->getOption('resourceName')) {
            case 'items':
                // No form elements for item.
                break;
            default:
                throw new RuntimeException('Invalid resource');
        }
        $this->add([
            'type' => 'submit',
            'name' => 'submit',
            'attributes' => [
                'value' => 'Confirm copy', // @translate
            ],
        ]);
    }
}
