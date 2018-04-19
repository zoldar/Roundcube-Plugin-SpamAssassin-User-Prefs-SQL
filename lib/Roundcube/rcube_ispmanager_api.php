<?php

class rcube_ispmanager_api
{
    private $FILTER_PREFIX = 'spam____';

    private $username;
    private $password;
    private $baseUrl;
    private $debug;
    private $filterName;

    function __construct($username, $password, $baseUrl, $salt = "NotASecret", $debug = false)
    {
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = $baseUrl;
        $this->filterName = $this->generateName($salt, $username);
        $this->debug = $debug;
        $this->initFilterIfAbsent();
    }

    function getFolders()
    {
        $folders = array();

        $result = $this->query(array(
            'func' => 'email.sorter.action.edit',
            'plid' => '_USER',
        ));

        if ($this->isOk($result)) {
            $options = $result['doc']['slist'];

            foreach ($options as $option) {
                if ($option['$name'] == 'folder') {
                    foreach ($option['val'] as $entry) {
                        if ($entry['$key'] != 'newfold') {
                            $folders[] = array($entry['$'], $entry['$key']);
                        }
                    }
                }
            }

        }

        return $folders;
    }

    function getSelection() {
        $filter = $this->findFilter($this->listFilters());
        $filterId = $filter['id']['$'];

        $selection = null;

        $result = $this->query(array(
            'func' => 'email.sorter.action',
            'plid' => '_USER',
            'elid' => $filterId
        ));

        if ($this->isOk($result)) {
            $actionId = $result['doc']['elem'][0]['id']['$'];

            $result = $this->query(array(
                'func' => 'email.sorter.action.edit',
                'plid' => '_USER/'.$this->filterName,
                'elid' => $actionId
            ));
        }

        if ($this->isOk($result)) {
            $action = $result['doc'];
            $type = $action['action']['$'];

            if ($type == 'keep') {
                $selection = 'INBOX';
            } elseif ($type == 'discard') {
                $selection = 'delete';
            } else {
                $selection = $action['folder']['$'];
            }
        }

        return $selection;
    }

    function setDestination($destination)
    {
        if ($destination == 'INBOX') {
            $this->setKeepAction();
        } elseif ($destination == 'delete') {
            $this->setDiscardAction();
        } else {
            $this->setSaveToFolderAction($destination);
        }
    }

    private function generateName($salt, $username) {
        $hash = sha1("$salt:$username");
        return $this->FILTER_PREFIX.substr($hash, 0, 8);
    }

    private function setKeepAction()
    {
        $this->clearActions();

        $result = $this->query(array(
            'func' => 'email.sorter.action.edit',
            'sok' => 'ok',
            'plid' => '_USER/' . $this->filterName,
            'elid' => '',
            'action' => 'keep',
            'folder' => '',
            'foldval' => '',
            'actval' => ''
        ));

        return $this->isOk($result);
    }

    private function setDiscardAction()
    {
        $this->clearActions();

        $result = $this->query(array(
            'func' => 'email.sorter.action.edit',
            'sok' => 'ok',
            'plid' => '_USER/' . $this->filterName,
            'elid' => '',
            'action' => 'discard',
            'folder' => '',
            'foldval' => '',
            'actval' => ''
        ));

        return $this->isOk($result);
    }

    private function setSaveToFolderAction($folder)
    {
        $this->clearActions();

        $result = $this->query(array(
            'func' => 'email.sorter.action.edit',
            'sok' => 'ok',
            'plid' => '_USER/' . $this->filterName,
            'elid' => '',
            'action' => 'fileinto',
            'folder' => $folder,
            'foldval' => '',
            'actval' => ''
        ));

        return $this->isOk($result);
    }

    private function clearActions()
    {
        $actionIds = $this->listActionIds();

        foreach ($actionIds as $actionId) {
            $this->query(array(
                'func' => 'email.sorter.action.delete',
                'sok' => 'ok',
                'plid' => '_USER/' . $this->filterName,
                'elid' => $actionId
            ));
        }
    }

    private function listActionIds()
    {
        $filter = $this->findFilter($this->listFilters());
        $filterId =  $filter['id']['$'];

        $ids = array();

        $result = $this->query(array(
            'func' => 'email.sorter.action',
            'plid' => '_USER',
            'elid' => $filterId
        ));

        if ($this->isOk($result)) {
            foreach ($result['doc']['elem'] as $entry) {
                $ids[] = $entry['id']['$'];
            }
        }

        return $ids;
    }

    private function listFilters()
    {
        return $this->query(array(
            'elid' => '_USER',
            'func' => 'email.sorter'
        ));
    }

    private function initFilterIfAbsent()
    {
        $result = $this->listFilters();

        if ($this->findFilter($result) === false) {
            $this->createFilter();
        }
    }

    private function createFilter()
    {
        $result = $this->query(array(
            'func' => 'email.sorter.action.add',
            'sok' => 'ok',
            'plid' => '_USER',
            'elid' => '',
            'name' => $this->filterName,
            'condcomp' => 'allof',
            'pos' => 'pos_last'
        ));

        if ($this->isOk($result)) {
            $result = $this->query(array(
                'func' => 'email.sorter.cond.edit',
                'sok' => 'ok',
                'plid' => '_USER/' . $this->filterName,
                'elid' => '',
                'what' => 'header',
                'params' => 'X-Spam-Status',
                'ifnot' => 'off',
                'mod' => 'contains',
                'values' => 'Yes'
            ));
        }

        if ($this->isOk($result)) {
            $result = $this->query(array(
                'func' => 'email.sorter.action.edit',
                'sok' => 'ok',
                'plid' => '_USER/' . $this->filterName,
                'elid' => '',
                'action' => 'keep',
                'folder' => '',
                'foldval' => '',
                'actval' => ''
            ));
        }

        return $this->isOk($result);
    }

    private function isOk($response)
    {
        return !isset($response['doc']['error']);
    }

    private function findFilter($response)
    {
        $filters = (array) $response['doc']['elem'];

        foreach ($filters as $filter) {
            if ($filter['name']['$'] == $this->filterName) {
                return $filter;
            }
        }

        return false;
    }

    private function query($params)
    {
        $queryParams = array();
        foreach ($params as $field => $param) {
            $queryParams[] = urlencode($field)
                . '=' . urlencode(str_replace('_USER', $this->username, $param));
        }

        $queryStr = implode('&', $queryParams);

        $fullUrl = $this->baseUrl . "?authinfo="
            . $this->username . ":" . $this->password
            . "&out=json&" . $queryStr;

        if ($this->debug) {
            rcube::write_log("ispmanager_api", 'Sending API request: ' . $fullUrl);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($this->debug) {
            rcube::write_log("ispmanager_api", 'Recevied API response: ' . $response);
        }

        return json_decode($response, true);
    }

}
