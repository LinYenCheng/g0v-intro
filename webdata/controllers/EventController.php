<?php

class EventController extends Pix_Controller
{
    public function init()
    {
        if (Pix_Session::get('user_name')) {
            $user = new StdClass;
            $user->name = Pix_Session::get('user_name');
            $user->id = Pix_Session::get('user_id');
            $this->view->user = $user;
        }
    }

    public function showAction()
    {
        list(, /*event*/, /*show*/, $id) = explode('/', $this->getURI());
        if (!$event = Event::find(strval($id))) {
            return $this->redirect('/');
        }
        $this->view->event = $event;
        if ($this->view->user) {
            $this->view->intro = Intro::search(array('event' => $event->id, 'created_by' => $this->view->user->id))->first();
            if ($this->view->intro) {
                $this->view->intro_voice = IntroVoice::find($this->view->intro->id);
            }
        }
    }

    public function downloadcsvAction()
    {
        list(, /*event*/, /*downloadcsv*/, $id) = explode('/', $this->getURI());
        if (!$event = Event::find(strval($id))) {
            return $this->alert("{$id} not found", '/');
        }

        $output = fopen('php://output', 'w');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $id . '.csv"');
        fputcsv($output, array(
            'slack帳號', '顯示名稱', '關鍵字', '建立時間', '頭像位置', '自介錄音',
        ));
        foreach (Intro::search(array('event' => $id))->order('created_at ASC') as $intro) {
            $data = json_decode($intro->data);
            fputcsv($output, array(
                $data->account,
                $data->display_name,
                $data->keyword,
                date('c', $intro->created_at),
                $data->avatar,
                $data->voice_path,
            ));
        }
        return $this->noview();
    }

    public function dataAction()
    {
        list(, /*event*/, /*data*/, $id) = explode('/', $this->getURI());
        if (!$event = Event::find(strval($id))) {
            return $this->alert("{$id} not found", '/');
        }

        $ret = array();
        foreach (Intro::search(array('event' => $id))->order('created_at ASC') as $intro) {
            $data = json_decode($intro->data);
            $obj = new StdClass;
            $obj->created_at = $intro->created_at;
            $obj->account = $data->account;
            $obj->display_name = $data->display_name;
            $obj->keyword = $data->keyword;
            $obj->avatar = $data->avatar;
            $obj->voice_path = $data->voice_path;
            $ret[] = $obj;
        }
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        return $this->json($ret);
    }


    public function saveintroAction()
    {
        list(, /*event*/, /*saveintro*/, $id) = explode('/', $this->getURI());
        if (!$event = Event::find(strval($id))) {
            return $this->alert("{$id} not found", '/');
        }

        if (!$this->view->user) {
            return $this->alert("must login", '/');
        }

        $data = array(
            'display_name' => $_POST['display_name'],
            'account' => $_POST['account'],
            'keyword' => $_POST['keyword'],
            'avatar' => $_POST['avatar'],
        );

        if ($_POST['record_data']) {
            if (strpos($_POST['record_data'], 'no-change:') === 0) {
                $data['voice_path'] = explode(':', $_POST['record_data'], 2)[1];
                $data['voice_length'] = intval($_POST['record_length']);
            } else {
                $tmpfile = tempnam('/tmp', 'tmp-file');
                file_put_contents($tmpfile, base64_decode($_POST['record_data']));
                $cmd = sprintf("ffmpeg -i %s %s", escapeshellarg($tmpfile), escapeshellarg($tmpfile . '.mp3'));
                system($cmd, $return_var);
                unlink($tmpfile);
                if ($return_var) {
                    throw new Exception("失敗");
                }
                $path = date('Ymd') . '/' . $this->view->user->id . '-' . crc32(uniqid()) . '.mp3';
                include(__DIR__ . '/../stdlibs/aws/aws-autoloader.php');
                $s3 = new Aws\S3\S3Client([
                    'region' => 'ap-northeast-1',
                    'version' => 'latest',
                ]);
                $s3->putObject([
                    'Bucket' => 'g0v-intro',
                    'Key' => $path,
                    'Body' => file_get_contents($tmpfile . '.mp3'),
                    'ACL' => 'public-read',
                    'ContentType' => 'audio/mpeg',
                ]);
                unlink($tmpfile . '.mp3');
                $data['voice_path'] = $path;
                $data['voice_length'] = intval($_POST['record_length']);
            }
        }


        if ($intro = Intro::search(array('event' => $id, 'created_by' => $this->view->user->id))->first()) {
            $intro->update(array(
                'data' => json_encode($data),
            ));
        } else {
            $intro = Intro::insert(array(
                'event' => $id,
                'created_at' => time(),
                'created_by' => $this->view->user->id,
                'data' => json_encode($data),
            ));
        }

        return $this->alert("自介儲存成功", "/event/show/{$id}");
    }
}
