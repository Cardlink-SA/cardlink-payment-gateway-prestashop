<?php

class AdminCardlink_CheckoutInstallmentsManagerController extends ModuleAdminController
{
    /**
     * @var Cardlink_Checkout
     */
    public $module;

    private $name;

    public function __construct()
    {
        parent::__construct();

        $this->name = str_replace('Controller', '', self::class);
        $this->display = 'edit';
        $this->bootstrap = true;
        $this->lang = false;
        $this->required_database = true;
        $this->table = Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS;
        $this->identifier = Cardlink_Checkout\Installments::IDENTIFIER;
        $this->className = Cardlink_Checkout\Installments::class;

        $this->meta_title = $this->l('Order Amount Based Installments Management');

        $acceptsOrderBasedInstallments = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS) == Cardlink_Checkout\Constants::ACCEPT_INSTALLMENTS_ORDER_AMOUNT;

        if (!$this->module->active || !$acceptsOrderBasedInstallments) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminDashboard'));
        }
    }

    public function display()
    {
        parent::display();
    }

    // This method generates the Add/Edit form
    public function renderForm()
    {

        if (!$this->loadObject(true)) {
            return;
        }

        if (Validate::isLoadedObject($this->object)) {
            $this->display = 'edit';
        } else {
            $this->display = 'add';
        }

        // Building the Add/Edit form
        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Order Based Installments Configuration')
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Min Amount'),
                        'name' => 'min_amount',
                        'size' => 10,
                        'required' => true
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Max Amount'),
                        'name' => 'max_amount',
                        'size' => 10,
                        'required' => true
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Maximum Installments'),
                        'desc' => $this->l('Valid values: 0 to 60.'),
                        'name' => 'max_installments',
                        'size' => 10,
                        'required' => true
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'button'
                ]
            ]
        ];

        $id = Tools::getValue(Cardlink_Checkout\Installments::IDENTIFIER, 0);

        $helper = new HelperForm();
        // Module, token and currentIndex
        $helper->table = $this->table;
        $helper->className = $this->className;
        $helper->identifier = $this->identifier;
        $helper->name_controller = self::class;
        $helper->token = Tools::getAdminTokenLite($this->name);
        $helper->currentIndex = $this->context->link->getAdminLink($this->name, false)
            . '&save' . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS
            . '&' . http_build_query([
                Cardlink_Checkout\Installments::IDENTIFIER => $id
            ]);
        $helper->submit_action = 'submit' . $this->name;

        $item = new Cardlink_Checkout\Installments((int)$id);

        if ($id != 0 && $item->id != 0) {
            $helper->fields_value['min_amount'] = $item->min_amount;
            $helper->fields_value['max_amount'] = $item->max_amount;
            $helper->fields_value['max_installments'] = max(0, min(60, $item->max_installments));
        } else {
            $helper->fields_value['min_amount'] = 0.0;
            $helper->fields_value['max_amount'] = 0.0;
            $helper->fields_value['max_installments'] = 0;
        }

        return $helper->generateForm([$form]);
    }

    public function postProcess()
    {
        parent::postProcess();

        $backUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->module->name;

        if (Tools::isSubmit('save' . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS)) {
            $id = (int)Tools::getValue(Cardlink_Checkout\Installments::IDENTIFIER, 0);

            if ($id <= 0) {
                $item = new Cardlink_Checkout\Installments();
            } else {
                $item = new Cardlink_Checkout\Installments((int)$id);
            }

            $item->min_amount = Tools::getValue('min_amount', $item->min_amount);
            $item->max_amount = Tools::getValue('max_amount', $item->max_amount);
            $item->max_installments = max(0, min(60, Tools::getValue('max_installments', $item->max_installments)));
            $item->save();

            Tools::redirect($backUrl);
        } else if (Tools::isSubmit('delete' . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS)) {
            $id = (int)Tools::getValue(Cardlink_Checkout\Installments::IDENTIFIER, 0);
            $item = new Cardlink_Checkout\Installments($id);
            $item->delete();

            Tools::redirect($backUrl);
        }
    }
}
