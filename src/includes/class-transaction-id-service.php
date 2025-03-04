<?php

if (!defined('ABSPATH')) {
    exit;
}
class TransactionService{

    public function generate_transaction_id() {
        // Generate a random 4-digit number
        $random_number = mt_rand(1000, 9999);
        
        // Get the current timestamp (to ensure uniqueness)
        $timestamp = time();
        
        // Combine the prefix, timestamp, and random number to form a unique ID
        $transaction_id = "DGLand{$timestamp}{$random_number}";

        return $transaction_id;
    }
}
?>
