<?php
class ControllerExtensionCronTipax extends Controller {
    // Example cron endpoint: index.php?route=extension/cron/tipax&action=sync_cities
    public function index() {
        $action = $this->request->get['action'] ?? '';
        if ($action === 'sync_cities') {
            $this->load->model('extension/shipping/tipax');
            $count = $this->model_extension_shipping_tipax->syncCities();
            $this->response->setOutput("Tipax cities synced: " . (int)$count);
        } else {
            $this->response->setOutput('Tipax cron OK');
        }
    }
}
