<?php

class RegistrationsController extends AppController {

    public $helpers = array('Time', 'Xls');
    public $uses = array('Appointment', 'AuthorizedCompany', 'Bill', 'Doctor', 'FollowUp', 'Further', 'Notification',
        'Patient', 'Registration', 'TimeSlot');
    public $components = array('Session',
        'CakePdf.CakePdf' => array(
            'prefix' => 'pdf',
            'layout' => 'CakePdf.pdf',
            'orientation' => 'L',
            'paper' => 'A5'
        )
    );

    public function showDailyRegistration($y = null, $m = null, $d = null) {

        $date = date("Y-m-d", mktime(0, 0, 0, (is_null($m) ? $m = date("m") : $m), (is_null($d) ? $d = date("d") : $d), (is_null($y) ? $y = date("Y") : $y)
                ));
        $results = $this->Registration->query("CALL getDailyRegistration('" . $date . "')");
        $this->set('results', $results);
        $this->set('year', $y);
        $this->set('month', $m);
        $this->set('day', $d);

        $this->set('title_for_layout', '心悠活診所 - 門診資料');
    }

    public function add($serial_number = null) {

        if (!is_null($serial_number)) {
            $this->set('serial_number', $serial_number);
        } else {
            $this->set('serial_number', null);
        }

        $this->set('title_for_layout', '心悠活診所 - 門診資料');

        if ($this->request->is('post')) {

            // 合併門診日期及門診時間
            $registration_time = $this->request->data['Registration']['registration_date'] . ' ' .
                    date('H:i:s', strtotime($this->request->data['Registration']['registration_datetime']));
            $this->request->data('Registration.registration_time', $registration_time);

            // 設定一個暫時的病患名稱
            if (empty($this->request->data['Registration']['patient_name'])) {
                $this->request->data('Registration.patient_name', '不能說的祕密');
            }

            // 檢查掛號證是否存在
            if (!empty($this->request->data['Registration']['serial_number'])) {

                $serial_number = trim($this->request->data['Registration']['serial_number']);
                $serial_number = str_pad($serial_number, 7, '0', STR_PAD_LEFT);

                $result = $this->Patient->findBySerialNumber($serial_number, array('id', 'name'));
                // 取出 id 及病患姓名
                if (!empty($result)) {
                    $this->request->data('Registration.patient_id', $result['Patient']['id']);
                    $this->request->data('Registration.patient_name', $result['Patient']['name']);
                    CakeLog::write('debug', 'RegistrationsController.add() - 掛號證 ' . $serial_number . ' 存在，取出 id 與病患姓名');

                    // 判斷是否未來已經有預約門診
                    $future_reg = $this->Registration->find('list', array(
                            'conditions' => array(
                                'Registration.patient_id' => $result['Patient']['id'], 
                                'Registration.registration_time > ' => date('Y-m-d H:i:s')
                                ),
                            'fields' => 'registration_time',
                                ));
                    $reg_time_str = '';
                    if (!empty($future_reg)) {
                        $is_exist_future_reg = true;
                        $future_reg = array_values($future_reg);
                        for ($i = 0; $i < sizeof($future_reg); $i++) {
                            $reg_time_str = $reg_time_str . substr($future_reg[$i], 0, -3) . '<br />';
                        }
                    } else {
                        $is_exist_future_reg = false;
                    }
                }
            }

            if ($this->Registration->save($this->request->data)) {

                $time_slot_id = $this->TimeSlot->getTimeSlotId($this->request->data['Registration']['registration_time']);
                $this->Registration->saveField('time_slot_id', $time_slot_id);

                $doctor_id = $this->Doctor->getDoctorId($this->request->data['Registration']['registration_time'], $time_slot_id);

                $str = 'INSERT INTO doctors_registrations (registration_id, doctor_id) VALUES (' . $this->Registration->id . ', ' . $doctor_id . ');';
                $this->Registration->query($str);

                if (!$is_exist_future_reg) {
                    $this->Session->setFlash('門診時段已新增！', 'alert', array(
                        'plugin' => 'TwitterBootstrap',
                        'class' => 'alert-success'
                    ));
                } else {
                    $this->Session->setFlash('門診時段已新增！<br /><br />注意！後續已有預約<br /><h3>' . $reg_time_str. '</h3>', 'alert', array(
                        'plugin' => 'TwitterBootstrap',
                        'class' => 'alert-error'
                    ));           
                }
                CakeLog::write('debug', 'RegistrationsController.add() - 新增門診時段(' . $this->Registration->id . ')');

                $date = new DateTime($this->request->data['Registration']['registration_time']);
                $this->redirect(array('action' => 'showDailyRegistration', $date->format('Y'), $date->format('m'), $date->format('d')));
            } else {

                $this->Session->setFlash('無法新增門診時段！', 'alert', array(
                    'plugin' => 'TwitterBootstrap',
                    'class' => 'alert-error'
                ));
                CakeLog::write('debug', 'RegistrationsController.add() - 無法新增門診時段');
            }
        }
    }

    public function edit($id = null) {

        $this->set('title_for_layout', '心悠活診所 - 門診資料');

        $this->Registration->id = $id;

        $this->set('doctors', $this->Doctor->find('list', array('fields' => 'id, description')));
        $this->set('identities', $this->Registration->Identity->find('list', array('fields' => array('id', 'description'))));
        $this->set('furthers', $this->Registration->Further->find('list', array('fields' => array('id', 'description'))));
        $this->set('notifications', $this->Notification->find('list', array('fields' => 'id, description')));

        $this->set('company', $this->AuthorizedCompany);

        // 檢查是否有下次預約時間的資料，有的話就塞進 view
        $hasNextAppointment = $this->isExistNextAppointment($id);
        // 檢查是否有放入追蹤名單的資料，有的話就塞進 view
        $hasFollowUp = $this->isExistFollowUp($id);
        // 檢查是否能修改後續動作的資料
        $isLinked = $this->isLinkedToOther($id);

        if ($this->request->is('get')) {

            $this->request->data = $this->Registration->read();

            $date = new DateTime($this->request->data['Registration']['registration_time']);
            $this->set('registration_date', $date->format('Y-m-d'));
            $this->set('registration_datetime', $date->format('h:i A'));
            $this->request->data('Registration.registration_date', $date->format('Y-m-d'));
            $this->request->data('Registration.registration_datetime', $date->format('h:i A'));

            // 如果後續動作是放入追蹤名單，就產生時間的預設值
            if ($hasFollowUp) {
                $this->set('selected_notification', null);
                $this->set('further_datetime', $date->format('h:i A'));
            }

            // 如果沒有下次預約時間，也沒有放入追蹤名單，就產生預設值
            if (!$hasNextAppointment && !$hasFollowUp) {
                $this->set('selected_notification', null);

                $further_date = $date->add(new DateInterval('P2W'));
                $this->set('further_date', $further_date->format('Y-m-d'));
                $this->set('further_datetime', $date->format('h:i A'));
                $this->request->data('Registration.further_date', $further_date->format('Y-m-d'));
                $this->request->data('Registration.further_datetime', $date->format('h:i A'));
            }
        } else {
            // 合併門診日期及門診時間
            $registration_time = $this->request->data['Registration']['registration_date'] . ' ' .
                    date('H:i:s', strtotime($this->request->data['Registration']['registration_datetime']));
            $further_time = $this->request->data['Registration']['further_date'] . ' ' .
                    date('H:i:s', strtotime($this->request->data['Registration']['further_datetime']));
            $this->request->data('Registration.registration_time', $registration_time);
            $this->request->data('Registration.further_time', $further_time);
            $date = new DateTime($registration_time);
            $date2 = new DateTime($further_time);

            // 使用者透過 Post Method 進來，通常是使用者按下『更新門診』按鈕，此時要把輸入資料塞進資料庫裡
            if ($this->Registration->save($this->request->data)) {

                // 更新門診時間，也要跟著修改診別
                $this->Registration->saveField('time_slot_id', $this->TimeSlot->getTimeSlotId($registration_time));

                // 更新門診時間，上次預約記錄的預約時間也跟著更新
                $previousAppointmentId = $this->Registration->getPreviousAppointmentId($id);
                if (!is_null($previousAppointmentId)) {
                    $this->Appointment->id = $previousAppointmentId;
                    $this->Appointment->saveField('appointment_time', $registration_time);
                    CakeLog::write('debug', 'RegistrationsController.edit() - 更新門診資料(' . $id . ')連結的預約記錄(' . $previousAppointmentId . ')的預約時間');
                }

                // 更新門診收入
                if ($this->isBillEmpty()) {
                    $billId = $this->Bill->getBillId($id);
                    if ($billId) {
                        $this->Registration->Bill->id = $billId;
                        $this->Registration->Bill->delete($billId);
                        CakeLog::write('debug', 'RegistrationsController.edit() - 刪除門診資料(' . $id . ')的門診收入');
                    }
                } else {
                    $this->Registration->Bill->set('registration_id', $id);
                    $billId = $this->Bill->getBillId($id);
                    if ($billId) {
                        $this->Registration->Bill->id = $billId;
                        $this->Registration->Bill->save($this->request->data);

                        // 病患有特約商店資格，要設定掛號費為 0，並且備註也要註明
                        if ($this->isExistAuthorizedCompany()) {
                            $this->Registration->Bill->saveField('registration_fee', 0);

                            $str = $this->addAuthorizedCompanyToNote($this->request->data['Registration']['note']);
                            $this->Registration->saveField('note', $str);
                        }
                    } else {
                        // 病患有特約商店資格，要設定掛號費為 0，並且備註也要註明
                        if ($this->isExistAuthorizedCompany()) {
                            $this->request->data('Bill.registration_fee', 0);

                            $str = $this->addAuthorizedCompanyToNote($this->request->data['Registration']['note']);
                            $this->Registration->saveField('note', $str);
                        }
                        $this->Registration->Bill->save($this->request->data);
                    }
                    CakeLog::write('debug', 'RegistrationsController.edit() - 更新門診資料(' . $id . ')的門診收入');
                }

                // 更新後續動作
                if (!$isLinked) {

                    if (strcmp($this->request->data['Further']['Further'], "1") == 0) {

                        $this->cleanUpForNextAppointment($id);

                        // 該筆門診記錄可能原本就是放在『預約回診時間』，這次想要改『回診時間』
                        // 也可能該筆門診記錄新建立，還沒有設定『回診時間』，因此要建立一個『回診記錄』
                        if ($hasNextAppointment) {

                            // 更新預約記錄的預約時間及提醒方式
                            $nextAppointmentId = $this->Registration->getNextAppointmentId($id);
                            $this->Appointment->id = $nextAppointmentId;
                            $this->Appointment->saveField('appointment_time', $this->request->data['Registration']['further_time']);
                            $this->Appointment->saveField('notification_id', $this->request->data['Registration']['notification_id']);
                            CakeLog::write('debug', 'RegistrationsController.edit() - 更新門診資料(' . $id . ')連結的下次預約記錄(' . $this->Appointment->id . ')');

                            // 更新下次預約記錄連結的門診資料的門診時間
                            $this->Registration->id = $this->Appointment->getNextRegistrationId($nextAppointmentId);
                            $this->Registration->saveField('registration_time', $this->request->data['Registration']['further_time']);

                            $time_slot_id = $this->TimeSlot->getTimeSlotId($this->request->data['Registration']['further_time']);
                            $this->Registration->saveField('time_slot_id', $time_slot_id);

                            $doctor_id = $this->Doctor->getDoctorId($this->request->data['Registration']['further_time'], $time_slot_id);
                            $str = 'UPDATE doctors_registrations SET doctor_id = ' . $doctor_id . ' WHERE registration_id = ' . $this->Registration->id . ';';
                            $this->Registration->query($str);

                            CakeLog::write('debug', 'RegistrationsController.edit() - 更新門診資料(' . $id . ')連結的下次預約記錄(' . $this->Appointment->id . ')' . '連結的門診資料(' . $this->Registration->id . ')的門診時間');
                        } else {

                            // 產生新的下次預約記錄
                            $this->Appointment->create();
                            $this->Appointment->save(
                                    array('Appointment' => array(
                                            'appointment_time' => $this->request->data['Registration']['further_time'],
                                            'contact_name' => $this->request->data['Registration']['patient_name'],
                                            'contact_phone' => $this->request->data['Patient']['phone'],
                                            'notification_id' => $this->request->data['Registration']['notification_id']))
                            );
                            CakeLog::write('debug', 'RegistrationsController.edit() - 建立門診資料(' . $id . ')連結的下次預約記錄(' . $this->Appointment->id . ')');
                            $this->Registration->setNextAppointment($id, $this->Appointment->id);
                            CakeLog::write('debug', 'RegistrationsController.edit() - 建立門診資料(' . $id . ')與下次預約記錄(' . $this->Appointment->id . ')的關係');

                            // 產生新的下次預約記錄的門診資料
                            $this->Registration->create();
                            $this->Registration->save(
                                    array('Registration' => array(
                                            'registration_time' => $this->request->data['Registration']['further_time'],
                                            'time_slot_id' => $this->TimeSlot->getTimeSlotId($this->request->data['Registration']['further_time']),
                                            'patient_name' => $this->request->data['Registration']['patient_name'],
                                            'patient_id' => $this->request->data['Registration']['patient_id']))
                            );
                            $time_slot_id = $this->TimeSlot->getTimeSlotId($this->request->data['Registration']['further_time']);
                            $doctor_id = $this->Doctor->getDoctorId($this->request->data['Registration']['further_time'], $time_slot_id);
                            $str = 'INSERT INTO doctors_registrations (registration_id, doctor_id) VALUES (' . $this->Registration->id . ', ' . $doctor_id . ');';
                            $this->Registration->query($str);

                            CakeLog::write('debug', 'RegistrationsController.edit() - 建立門診資料(' . $id . ')連結的下次預約記錄(' . $this->Appointment->id . ')連結的門診資料(' . $this->Registration->id . ')');
                            $this->Registration->setPreviousAppointment($this->Registration->id, $this->Appointment->id);
                            CakeLog::write('debug', 'RegistrationsController.edit() - 建立門診資料(' . $id . ')連結的下次預約記錄(' . $this->Appointment->id . ')連結的門診資料(' . $this->Registration->id . ')與下次預約記錄的關係');
                        }
                    }

                    if (strcmp($this->request->data['Further']['Further'], "2") == 0) {

                        $this->cleanUpForFollowUp($id);

                        // 是否有在追蹤名單
                        if ($hasFollowUp) {

                            // 有的話，就更新追蹤名單的追蹤時間
                            $this->FollowUp->id = $this->FollowUp->getFollowUpId($id);
                            $this->FollowUp->saveField('follow_up_time', $date2->format('Y-m-d'));
                            CakeLog::write('debug', 'RegistrationsController.edit() - 更新門診資料(' . $id . ')連結的追蹤名單(' . $this->FollowUp->id . ')的時間');
                        } else {

                            // 沒有的話，就產生新的追蹤名單
                            $this->FollowUp->create();
                            $this->FollowUp->save(array('FollowUp' => array(
                                    'registration_id' => $id,
                                    'patient_id' => $this->request->data['Registration']['patient_id'],
                                    'follow_up_time' => $date2->format('Y-m-d')
                                    )));
                            CakeLog::write('debug', 'RegistrationsController.edit() - 建立門診資料(' . $id . ')連結的追蹤名單(' . $this->FollowUp->id . ')');
                        }
                    }

                    if (strcmp($this->request->data['Further']['Further'], "3") == 0) {

                        $this->cleanUpForNextAppointment($id);
                        $this->cleanUpForFollowUp($id);
                    }

                    if (strcmp($this->request->data['Further']['Further'], "1") == 0 ||
                            strcmp($this->request->data['Further']['Further'], "2") == 0 ||
                            strcmp($this->request->data['Further']['Further'], "3") == 0) {
                        // 若病患確實回診，且追蹤名單有該病患，就要修改追蹤名單的回診時間
                        $result = $this->FollowUp->find('all', array(
                            'conditions' => array('FollowUp.patient_id' => $this->request->data['Registration']['patient_id'], 'FollowUp.come_back_time IS NULL'),
                            'fields' => 'id',
                                ));
                        if (!empty($result)) {
                            $this->FollowUp->setComBackTime($result[0]['FollowUp']['id'], $date->format('Y-m-d'));
                            CakeLog::write('debug', 'RegistrationsController.edit() - 更新門診資料(' . $id . ')連結的追蹤名單(' . $result[0]['FollowUp']['id'] . ')的回診時間');
                        }
                    }
                } else {

                    $this->Session->setFlash('門診資料與其他記錄已連結，不能更新『後續動作』！', 'alert', array(
                        'plugin' => 'TwitterBootstrap',
                        'class' => 'alert-error'
                    ));
                    CakeLog::write('debug', 'RegistrationsController.edit() - 門診資料(' . $id . ')與其他記錄已連結，不能更新『後續動作』');
                }

                $this->Session->setFlash('門診時段已更新！', 'alert', array(
                    'plugin' => 'TwitterBootstrap',
                    'class' => 'alert-success'
                ));
                CakeLog::write('debug', 'RegistrationsController.edit() - 更新門診資料(' . $id . ')');

                $this->redirect(array('action' => 'showDailyRegistration', $date->format('Y'), $date->format('m'), $date->format('d')));
            } else {

                $this->Session->setFlash('無法更新門診資料！', 'alert', array(
                    'plugin' => 'TwitterBootstrap',
                    'class' => 'alert-error'
                ));
                CakeLog::write('debug', 'RegistrationsController.edit() - 無法更新門診資料(' . $id . ')');

                $this->redirect(array('action' => 'showDailyRegistration', $date->format('Y'), $date->format('m'), $date->format('d')));
            }
        }
    }

    public function delete($id = null) {

        if ($this->request->is('get')) {
            throw new MethodNotAllowedException();
        }

        $this->Registration->id = $id;
        $date = new DateTime($this->Registration->field('registration_time'));

        if (!$this->isLinkedToOther($id)) {

            $this->cleanUpForNextAppointment($id);
            $this->cleanUpForFollowUp($id);

            $billId = $this->Bill->getBillId($id);
            if (!is_null($billId)) {
                $this->Registration->Bill->delete($billId);
                CakeLog::write('debug', 'RegistrationsController.delete() - 刪除門診資料(' . $id . ')的門診收入');
            }

            $followUpId = $this->FollowUp->getFollowUpId($id);
            if (!is_null($followUpId)) {
                $this->Registration->FollowUp->delete($followUpId);
                CakeLog::write('debug', 'RegistrationsController.delete() - 刪除門診資料(' . $id . ')連結的追蹤名單');
            }

            $previousAppointmentId = $this->Registration->getPreviousAppointmentId($id);
            if (!is_null($previousAppointmentId)) {

                $registrationId = $this->Appointment->getPreviousRegistrationId($previousAppointmentId);
                if (!is_null($registrationId)) {
                    // 先刪除預約記錄與某次門診記錄的關係
                    $this->Appointment->deletePreviousRegistration($registrationId, $previousAppointmentId);
                    CakeLog::write('debug', 'RegistrationsController.delete() - 刪除門診資料(' . $id . ')連結的前次預約記錄(' . $previousAppointmentId . ')與前次預約記錄連結的前次門診資料(' . $registrationId . ')的關係');

                    $this->loadModel('Further');
                    // 取得該次門診的後續動作 id
                    $furtherId = $this->Further->getFurtherId($registrationId);
                    if (!is_null($furtherId)) {
                        $this->Further->setFurtherToFinish($furtherId);
                        CakeLog::write('debug', 'RegistrationsController.delete() - 更新門診資料(' . $id . ')連結的前次預約記錄(' . $previousAppointmentId . ')連結的前次門診資料(' . $registrationId . ')的後續動作為『結束』');
                    }
                }

                $this->Registration->deletePreviousAppointment($id, $previousAppointmentId);
                CakeLog::write('debug', 'RegistrationsController.delete() - 刪除門診資料(' . $id . ')與門診資料連結的前次預約記錄(' . $previousAppointmentId . ')的關係');
                $this->Appointment->delete($previousAppointmentId);
                CakeLog::write('debug', 'RegistrationsController.delete() - 刪除門診資料(' . $id . ')連結的前次預約記錄(' . $previousAppointmentId . ')');
            }

            $this->Registration->delete($id);
            $this->Session->setFlash('門診資料已刪除！', 'alert', array(
                'plugin' => 'TwitterBootstrap',
                'class' => 'alert-success'
            ));
            CakeLog::write('debug', 'RegistrationsController.delete() - 刪除門診資料(' . $id . ')');
        } else {
            $this->Session->setFlash('門診資料與其它記錄已連結，不能刪除！', 'alert', array(
                'plugin' => 'TwitterBootstrap',
                'class' => 'alert-error'
            ));
            CakeLog::write('debug', 'RegistrationsController.delete() - 門診資料(' . $id . ')與其它記錄已連結，不能刪除');
        }

        $this->redirect(array('action' => 'showDailyRegistration', $date->format('Y'), $date->format('m'), $date->format('d')));
    }

    public function downloadDailyRegistration($y = null, $m = null, $d = null) {

        $date = date("Y-m-d", mktime(0, 0, 0, (is_null($m) ? $m = date("m") : $m), (is_null($d) ? $d = date("d") : $d), (is_null($y) ? $y = date("Y") : $y)
                ));
        $results = $this->Registration->query("CALL getDailyRegistration('" . $date . "')");
        $this->set('results', $results);
        $this->set('year', $y);
        $this->set('month', $m);
        $this->set('day', $d);
    }

    public function downloadDailyRegistrationByDoctor($y = null, $m = null, $d = null, $doctor_id = null) {

        $date = date("Y-m-d", mktime(0, 0, 0, (is_null($m) ? $m = date("m") : $m), (is_null($d) ? $d = date("d") : $d), (is_null($y) ? $y = date("Y") : $y)
                ));
        $results = $this->Registration->query("CALL getDailyRegistrationByDoctor('" . $date . "', '" . $doctor_id . "')");
        $this->set('results', $results);
        $this->set('year', $y);
        $this->set('month', $m);
        $this->set('day', $d);

        $this->Doctor->id = $doctor_id;
        if (strcmp($this->Doctor->field('description'), '陳介仁') == 0) {
            $doctor = 'Fan';
        } else {
            $doctor = 'Jian';
        }
        $this->set('doctor', $doctor);
    }

    public function pdf_print($id = null) {

        $this->Registration->id = $id;
        $this->Patient->id = $this->Registration->field('patient_id');
        $result = $this->Registration->query('SELECT appointment_time FROM furthers_appointment_time WHERE registration_id = ' . $id);
        $appointment_time = $result[0]['furthers_appointment_time']['appointment_time'];

        $this->set('serial_number', $this->Patient->field('serial_number'));
        $this->set('name', $this->Registration->field('patient_name'));
        $this->set('appointment_time', $appointment_time);

        $time_slot_id = $this->TimeSlot->getTimeSlotId($appointment_time);
        $doctor_id = $this->Doctor->getDoctorId($appointment_time, $time_slot_id);

        if ($doctor_id == 1) {
            $doctor = 'Fan';
        } else {
            $doctor = 'Jian';
        }
        $this->set('doctor', $doctor);

        $this->CakePdf->setFilename($this->Patient->field('serial_number'));
    }

    public function search() {

        $this->set('title_for_layout', '心悠活診所 - 門診資料');

        if (!is_null($this->request->data['Registration']['parm'])) {

            $patients = $this->Patient->find('list', array(
                'conditions' => array('Patient.name LIKE' => '%' . $this->request->data['Registration']['parm'] . '%'),
                'fields' => array('serial_number'),
                'order' => array('Patient.serial_number DESC')
                    ));

            if (empty($patients)) {

                $serial_number = str_pad($this->request->data['Registration']['parm'], 7, '0', STR_PAD_LEFT);
                $patients = $this->Patient->find('list', array(
                    'conditions' => array('Patient.serial_number' => $serial_number),
                    'fields' => array('serial_number'),
                    'order' => array('Patient.serial_number DESC')
                        ));

                if (empty($patients)) {

                    $patients = $this->Patient->find('list', array(
                        'conditions' => array('Patient.birthday' => $this->request->data['Registration']['parm']),
                        'fields' => array('serial_number'),
                        'order' => array('Patient.serial_number DESC')
                            ));

                    if (empty($patients)) {
                        $this->set('patients', null);
                    }
                }
            }

            $sn = array_values($patients);

            $results = array();
            for ($i = 0; $i < sizeof($sn); $i++) {
                array_push($results, $this->Registration->query("CALL getDailyRegistrationBySerialNumber('" . $sn[$i] . "')"));
            }

            if (!empty($results)) {
                $this->set('results', $results);
            } else {
                $this->set('results', null);
            }
        } else {
            $this->set('results', null);
        }
    }

    private function isExistNextAppointment($id = null) {

        // 檢查該筆門診記錄的後續動作為何
        // 若是預約，就把預約時間跟提醒方式塞進 view
        $hasNextAppointment = false;
        $nextAppointmentId = $this->Registration->getNextAppointmentId($id);

        if (!is_null($nextAppointmentId)) {

            $this->Appointment->id = $nextAppointmentId;
            $this->set('selected_notification', $this->Appointment->field('notification_id'));
            $date = new DateTime($this->Appointment->field('appointment_time'));
            $this->set('further_date', $date->format('Y-m-d'));
            $this->set('further_datetime', $date->format('h:i A'));

            $hasNextAppointment = true;
        }

        return $hasNextAppointment;
    }

    private function isExistFollowUp($id = null) {

        // 檢查該筆門診記錄的後續動作為何
        // 若是放入追蹤名單，就把追蹤時間塞進 view
        $hasFollowUp = false;
        $followUpId = $this->FollowUp->getFollowUpId($id);

        if (!is_null($followUpId)) {

            $this->FollowUp->id = $followUpId;
            $date = new DateTime($this->FollowUp->field('follow_up_time'));
            $this->set('further_date', $date->format('Y-m-d'));

            $hasFollowUp = true;
        }

        return $hasFollowUp;
    }

    private function isLinkedToOther($id = null) {

        $isLinked = false;

        $nextAppointmentId = $this->Registration->getNextAppointmentId($id);

        if (!is_null($nextAppointmentId)) {

            $registrationId = $this->Appointment->getNextRegistrationId($nextAppointmentId);

            if (!is_null($registrationId)) {

                $billId = $this->Bill->getBillId($registrationId);
                if (!is_null($billId)) {

                    $isLinked = true;
                }

                $furtherId = $this->Further->getFurtherId($registrationId);
                if (!is_null($furtherId)) {

                    $isLinked = true;
                }
            }

            return $isLinked;
        } else {

            return $isLinked;
        }
    }

    private function isBillEmpty() {

        $isEmpty = false;

        if (strcmp($this->request->data['Bill']['registration_fee'], '') == 0 &&
                strcmp($this->request->data['Bill']['copayment'], '') == 0 &&
                strcmp($this->request->data['Bill']['drug_expense'], '') == 0 &&
                strcmp($this->request->data['Bill']['own_expense'], '') == 0) {

            $isEmpty = true;
        }

        return $isEmpty;
    }

    private function isExistAuthorizedCompany() {

        $isExist = true;

        $result = $this->Patient->findById($this->request->data['Registration']['patient_id'], array('authorized_company_id'));
        if (empty($result['Patient']['authorized_company_id'])) {
            $isExist = false;
        }

        return $isExist;
    }

    private function addAuthorizedCompanyToNote($str = null) {

        $rId = $this->Patient->findById($this->request->data['Registration']['patient_id'], array('authorized_company_id'));
        $result = $this->AuthorizedCompany->findById($rId['Patient']['authorized_company_id'], array('description'));

        if (empty($str)) {
            $str = '特約/' . $result['AuthorizedCompany']['description'];
        } else {
            if (!strstr($str, '特約')) {
                $str = $str . ', 特約/' . $result['AuthorizedCompany']['description'];
            }
        }

        return $str;
    }

    private function cleanUpForNextAppointment($id = null) {

        // 該筆記錄可能原本不是選擇『預約回診日期』，而是放在追蹤名單
        $followUpId = $this->FollowUp->getFollowUpId($id);

        if (!is_null($followUpId)) {

            // 若是已放入追蹤名單，就刪除追蹤名單的紀錄
            $this->FollowUp->delete($followUpId);
            CakeLog::write('debug', 'RegistrationsController.cleanUpForNextAppointment() - 刪除門診資料(' . $id . ')連結的追蹤名單(' . $followUpId . ')');
        }
    }

    private function cleanUpForFollowUp($id = null) {

        // 該筆記錄可能原本不是選擇『放入追蹤名單』，而是預約下次回診時間
        $nextAppointmentId = $this->Registration->getNextAppointmentId($id);

        if (!is_null($nextAppointmentId)) {

            $registrationId = $this->Appointment->getNextRegistrationId($nextAppointmentId);
            if (!is_null($registrationId)) {

                $this->Registration->deletePreviousAppointment($registrationId, $nextAppointmentId);
                CakeLog::write('debug', 'RegistrationsController.cleanUpForFollowUp() - 刪除門診資料(' . $id . ')連結的下次預約記錄(' . $nextAppointmentId . ')連結的門診資料(' . $registrationId . ')與下次預約記錄的關係');
                $this->Registration->delete($registrationId);
                CakeLog::write('debug', 'RegistrationsController.cleanUpForFollowUp() - 刪除門診資料(' . $id . ')連結的下次預約記錄(' . $nextAppointmentId . ')連結的門診資料(' . $registrationId . ')');
            }

            // 先刪除門診與下次預約記錄的關係
            $this->Registration->deleteNextAppointment($id, $nextAppointmentId);
            CakeLog::write('debug', 'RegistrationsController.cleanUpForFollowUp() - 刪除門診資料(' . $id . ')與下次預約記錄(' . $nextAppointmentId . ')的關係');
            // 再刪除下次預約記錄
            $this->Appointment->delete($nextAppointmentId);
            CakeLog::write('debug', 'RegistrationsController.cleanUpForFollowUp() - 刪除門診資料(' . $id . ')連結的下次預約記錄(' . $nextAppointmentId . ')');
        }
    }

}

?>
