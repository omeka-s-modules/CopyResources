<?php
namespace CopyResources\Controller\Admin;

use CopyResources\Stdlib\CopyResources;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;

class IndexController extends AbstractActionController
{
    protected $copyResources;

    public function __construct(CopyResources $copyResources)
    {
        $this->copyResources = $copyResources;
    }

    public function copyConfirmAction()
    {
        $resourceName = $this->params('resource');
        $resourceId = $this->params('id');

        if (!in_array($resourceName, ['items'])) {
            return $this->redirect()->toRoute('admin');
        }

        $resource = $this->api()->read($resourceName, $resourceId)->getContent();

        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'copy'], true));
        $form->setButtonLabel($this->translate('Confirm copy'));

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/copy-resources-confirm');
        $view->setVariable('resourceName', $resourceName);
        $view->setVariable('resourceId', $resourceId);
        $view->setVariable('resource', $resource);
        $view->setVariable('form', $form);
        $view->setVariable('linkTitle', true); // show-details.phtml needs this
        return $view;
    }

    public function copyAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }

        $resourceName = $this->params('resource');
        $resourceId = $this->params('id');

        if (!in_array($resourceName, ['items'])) {
            return $this->redirect()->toRoute('admin');
        }

        $resource = $this->api()->read($resourceName, $resourceId)->getContent();

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->params()->fromPost());

        if ($form->isValid()) {
            // @todo: copy resource
            $this->messenger()->addSuccess('Resource successfully copied'); // @translate
            return $this->redirect()->toRoute('admin/id', ['controller' => 'item', 'action' => 'show', 'id' => 22705]);
        } else {
            return $this->redirect()->toRoute('admin');
        }
    }
}
