<?php

namespace App\Actions\Email;

use App\Actions\BaseAction;
use App\Database;

class EmailAccountDeleteAction extends BaseAction
{
    public function __construct(private Database $db)
    {
    }

    public function execute(): void
    {
        $id = (int)($_POST['account_id'] ?? 0);
        if ($id > 0) {
            $this->db->query("DELETE FROM email_accounts WHERE id = :id", [':id' => $id]);
        }
        $this->redirect("index.php?tab=emails");
    }
}
