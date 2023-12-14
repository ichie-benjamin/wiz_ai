<?php
require_once realpath(dirname(__FILE__)) . "/Action.class.php";
class Tags extends Action{
    public function __construct(){
        parent::__construct('tags'); 
    }

    public function getList(?string $order = NULL, int $limit = 999999) {
        return $this->query("SELECT * FROM $this->table ORDER BY item_order ASC LIMIT $limit");
    }

    public function getListFront() {
        return $this->query("SELECT * FROM $this->table WHERE status='1' ORDER BY item_order ASC");
    }

    public function getBySlug(?string $slug) {
        return $this->query("SELECT * FROM $this->table WHERE slug='$slug'")->Fetch();
    }

    public function getMaxOrder() {
        return $this->query("SELECT MAX(item_order) AS max_order FROM $this->table")->Fetch();
    }    
}