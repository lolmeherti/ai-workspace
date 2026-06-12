<?php

namespace App\Controllers;

use App\Database;
use App\Enums\Action;
use App\Enums\ApiAction;
use App\Actions\Email\EmailAccountAddAction;
use App\Actions\Email\EmailAccountDeleteAction;
use App\Actions\Email\EmailListAction;
use App\Actions\Email\EmailBodyAction;
use App\Actions\Email\EmailReplyAction;

class EmailController extends BaseController
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $actionVal = $_POST['action'] ?? '';
            $action = Action::tryFrom($actionVal);

            if ($action === Action::ADD_EMAIL_ACCOUNT) {
                (new EmailAccountAddAction($this->db))->execute();
            } elseif ($action === Action::DELETE_EMAIL_ACCOUNT) {
                (new EmailAccountDeleteAction($this->db))->execute();
            } elseif ($action === Action::SEND_REPLY) {
                (new EmailReplyAction($this->db))->execute();
            }
        } else {
            $apiActionVal = $_GET['api_action'] ?? '';
            $apiAction = ApiAction::tryFrom($apiActionVal);

            if ($apiAction === ApiAction::GET_EMAILS) {
                (new EmailListAction($this->db))->execute();
            } elseif ($apiAction === ApiAction::GET_EMAIL_BODY) {
                (new EmailBodyAction($this->db))->execute();
            }
        }
    }
}
