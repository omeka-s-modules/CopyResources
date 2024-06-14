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

        if (!in_array($resourceName, ['items', 'item_sets', 'site_pages', 'sites'])) {
            throw new RuntimeException('Invalid resource name');
        }

        $resource = $this->api()->read($resourceName, $resourceId)->getContent();

        $form = $this->getForm(CopyConfirmForm::class, ['resourceName' => $resourceName]);
        $form->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'copy'], true));

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate(sprintf('common/copy-resources/copy-confirm-%s', $resourceName));
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

        if (!in_array($resourceName, ['items', 'item_sets', 'site_pages', 'sites'])) {
            throw new RuntimeException('Invalid resource name');
        }

        $resource = $this->api()->read($resourceName, $resourceId)->getContent();

        $form = $this->getForm(CopyConfirmForm::class, ['resourceName' => $resourceName]);
        $form->setData($this->params()->fromPost());

        if ($form->isValid()) {
            // Prepare the form data to be passed as copy options.
            $formData = $form->getData();
            unset($formData['submit']);
            unset($formData['copyconfirmform_csrf']);
            // Call the relevant copy method and redirect to the resource copy.
            try {
                switch ($resourceName) {
                    case 'items':
                        $resourceCopy = $this->copyResources->copyItem($resource, $formData);
                        $this->messenger()->addSuccess('Item successfully copied. The copy is below.'); // @translate
                        return $this->redirect()->toRoute('admin/id', ['controller' => 'item', 'action' => 'show', 'id' => $resourceCopy->id()]);
                    case 'item_sets':
                        $resourceCopy = $this->copyResources->copyItemSet($resource, $formData);
                        $this->messenger()->addSuccess('Item set successfully copied. The copy is below.'); // @translate
                        return $this->redirect()->toRoute('admin/id', ['controller' => 'item-set', 'action' => 'show', 'id' => $resourceCopy->id()]);
                    case 'site_pages':
                        $resourceCopy = $this->copyResources->copySitePage($resource, $formData);
                        $this->messenger()->addSuccess('Page successfully copied. The copy is below.'); // @translate
                        return $this->redirect()->toRoute('admin/site/slug/page/default', ['site-slug' => $resourceCopy->site()->slug(), 'page-slug' => $resourceCopy->slug()]);
                    case 'sites':
                        $resourceCopy = $this->copyResources->copySite($resource, $formData);
                        $this->messenger()->addSuccess('Site successfully copied. The copy is below.'); // @translate
                        return $this->redirect()->toRoute('admin/site/slug', ['site-slug' => $resourceCopy->slug()]);
                }
            } catch (\Exception $e) {
                // There was an error.
                $this->messenger()->addError('There was an error during the copy process. If a copy was created, it is likely incomplete. The error message is below. Check your Omeka log for the complete error.'); // @translate
                $this->messenger()->addError($e->getMessage());
                $this->logger()->err((string) $e);
                // Redirect to the previous page.
                $url = $this->getRequest()->getHeader('Referer')->getUri();
                return $this->redirect()->toUrl($url);
            }
        } else {
            // Redirect to the previous page.
            $this->messenger()->addFormErrors($form);
            $url = $this->getRequest()->getHeader('Referer')->getUri();
            return $this->redirect()->toUrl($url);
        }
    }
}
