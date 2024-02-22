<?php
namespace CopyResources\Controller\Admin;

use CopyResources\Stdlib\CopyResources;
use Laminas\Mvc\Controller\AbstractActionController;

class IndexController extends AbstractActionController
{
    protected $copyResources;

    public function __construct(CopyResources $copyResources)
    {
        $this->copyResources = $copyResources;
    }

    public function indexAction()
    {
    }
}
