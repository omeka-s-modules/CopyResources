<?php
namespace CopyResources\Controller\Admin;

use CopyResources\Form\CopyConfirmForm;
use CopyResources\Stdlib\CopyResources;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Mvc\Exception\RuntimeException;

class IndexController extends AbstractActionController
{
    protected $copyResources;

    public function __construct(CopyResources $copyResources)
    {
        $this->copyResources = $copyResources;
    }

    public function copyConfirmAction()
    {
        $resourceName = $this->params('resource-name');
        $resourceId = $this->params('id');

        // Validate resource name and set resource-specific variables.
        switch ($resourceName) {
            case 'items':
                $template = 'common/copy-resources/copy-item-confirm';
                break;
            default:
                throw new RuntimeException('Invalid resource');
        }

        $resource = $this->api()->read($resourceName, $resourceId)->getContent();

        $form = $this->getForm(CopyConfirmForm::class, ['resourceName' => $resourceName]);
        $form->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'copy'], true));

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate($template);
        $view->setVariable('form', $form);
        $view->setVariable('resource', $resource);
        return $view;
    }

    public function copyAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }

        $resourceName = $this->params('resource-name');
        $resourceId = $this->params('id');

        // Validate resource name and set resource-specific variables.
        switch ($resourceName) {
            case 'items':
                $copyMethod = 'copyItem';
                $controller = 'item';
                $action = 'show';
                break;
            default:
                throw new RuntimeException('Invalid resource');
        }

        $resource = $this->api()->read($resourceName, $resourceId)->getContent();

        $form = $this->getForm(CopyConfirmForm::class, ['resourceName' => $resourceName]);
        $form->setData($this->params()->fromPost());

        if ($form->isValid()) {
            $resourceCopy = $this->copyResources->$copyMethod($resource);
            $this->messenger()->addSuccess('Resource successfully copied. The copy is below.'); // @translate
            return $this->redirect()->toRoute('admin/id', ['controller' => $controller, 'action' => $action, 'id' => $resourceCopy->id()]);
        } else {
            return $this->redirect()->toRoute('admin');
        }
    }
}
