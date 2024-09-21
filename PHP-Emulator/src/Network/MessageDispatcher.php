<?php

namespace App\Network;

use Ratchet\ConnectionInterface;
use App\Database\User\Get\GetUserData;
use SplObjectStorage;
use App\WebSocket\User\UserSessionManager;
use App\Network\MessageSender;

class MessageDispatcher {
    private $userDataFetcher;
    private $sessionManager;
    private $clients;
    private $messageSender;

    public function __construct(GetUserData $userDataFetcher, UserSessionManager $sessionManager, SplObjectStorage $clients) {
        $this->userDataFetcher = $userDataFetcher;
        $this->sessionManager = $sessionManager;
        $this->clients = $clients;
        $this->messageSender = new MessageSender();
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (isset($data['userId'])) {
            $this->handleUserData($from, $data['userId']);
        }
    }

    private function handleUserData(ConnectionInterface $conn, $id) {
        $userData = $this->userDataFetcher->getUserById($id);
        
        if (!$userData) {
            Logger::log("ID {$id} not found.");
            return;
        }
        
        $username = $userData['username'];
        $existingSession = $this->sessionManager->getUserSession($id);
        
        if ($existingSession && $existingSession !== $conn) {
            $existingUsername = $existingSession->userData['username'] ?? 'Unknown';
            $this->sessionManager->disconnectPreviousSession($id, $conn);
            
            Logger::log("User {$existingUsername} had a duplicate session. Previous session has been removed.");
            
            $this->messageSender->broadcastMessage($this->clients, [
                'type' => 'userDuplicateSession',
                'id' => $id,
                'removedSessionId' => $existingSession->resourceId
            ]);
        }
    
        $conn->userData = $userData;
        $this->sessionManager->setUserSession($id, $conn);
    
        Logger::log("{$username} has joined the adventure");
    
        $users = [];
        foreach ($this->sessionManager->getAllSessions() as $session) {
            $userDataFiltered = $this->messageSender->filterSensitiveData($session->userData);
            $users[$session->userData['id']] = $userDataFiltered;
        }
    
        $this->messageSender->broadcastMessage($this->clients, [
            'type' => 'userUpdate',
            'data' => $users
        ]);
    }
}