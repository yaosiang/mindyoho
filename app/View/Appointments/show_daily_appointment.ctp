<?php
$code =
        "var appDate = new Date(" . $year . ", " . $month . ", " . $day . ");
        $('#dp').datepicker({weekStart: 1})
            .on('changeDate', function(ev){
                regDate = new Date(ev.date);
                $('#appDate').text($('#dp').data('date'));
                $('#dp').datepicker('hide');

                var dateStr = $('#dp').data('date');
                dateStr = dateStr.replace('-', '/');
                dateStr = dateStr.replace('-', '/');
                currectUrl = location.pathname;
                var str = currectUrl.lastIndexOf('showDailyAppointment');
                var str2 = 'showDailyAppointment/';
                if ((str+str2.length) == currectUrl.length) {
                    window.location = currectUrl.concat(dateStr);
                } else {
                    currectUrl = currectUrl.substring(0, str).concat(str2);
                    window.location = currectUrl.concat(dateStr);
                }
            });";
echo $this->Html->scriptBlock($code, array('inline' => false));
$count = 1;
?>

<div class="row-fluid">
    <div class="span6">
        <h1>
            <span id="appDate"><?php echo $year; ?>-<?php echo $month; ?>-<?php echo $day; ?></span>
            <?php
            $date = new DateTime($year . '-' . $month . '-' . $day);
            if ($date->format('N') == 1) {
                echo ' 星期一';
            } elseif ($date->format('N') == 2) {
                echo ' 星期二';
            } elseif ($date->format('N') == 3) {
                echo ' 星期三';
            } elseif ($date->format('N') == 4) {
                echo ' 星期四';
            } elseif ($date->format('N') == 5) {
                echo ' 星期五';
            } elseif ($date->format('N') == 6) {
                echo ' 星期六';
            } else {
                echo ' 星期天';
            }
            ?>
            預約記錄
            <?php echo $this->Html->link('', '#', array('class' => 'btn', 'icon' => 'calendar', 'id' => 'dp', 'data-date-format' => 'yyyy-mm-dd', 'data-date' => $year . '-' . $month . '-' . $day)); ?>
        </h1>
    </div>
    <div class="span6">
        <?php
        echo $this->Form->create('Appointment', array('class' => 'well form-search pull-right inline', 'action' => 'searchSerialNumber'));
        echo $this->Form->input('parm', array(
            'type' => 'text',
            'class' => 'input-small',
            'placeholder' => '聯絡姓名 or 生日',
            'append' => array('找掛號證', array('wrap' => 'button', 'class' => 'btn', 'type' => 'submit')),
        ));
        echo $this->Html->para(null, '請輸入聯絡姓名 or 生日');
        echo $this->Form->end();
        echo ' ';
        echo $this->Form->create('Appointment', array('class' => 'well form-search pull-right inline', 'action' => 'search'));
        echo $this->Form->input('parm', array(
            'type' => 'text',
            'class' => 'input-small',
            'placeholder' => '聯絡姓名',
            'append' => array('找預約', array('wrap' => 'button', 'class' => 'btn', 'type' => 'submit')),
        ));
        echo $this->Html->para(null, '請輸入聯絡姓名');
        echo $this->Form->end();
        ?>
    </div>            
</div>

<div class="row-fluid">
    <div class="span12">
    </div>
</div>

<div class="btn-group">
    <?php echo $this->Html->link('新增預約記錄', '/appointments/add', array('class' => 'btn pull-left', 'icon' => 'plus')); ?>
</div>

<hr />

<table class="table table-hover">
    <thead>
    <th>序號</th>
    <th>時間</th>
    <th>聯絡姓名</th>
    <th>聯絡電話</th>
    <th>提醒方式</th>
    <th>備註</th>
    <th>爽約</th>
    <th>聯絡時間</th>
    <th>關懷結果</th>
    <th>編輯</th>
    <th>刪除</th>
</thead>
<tbody>
    <?php foreach ($results as $result): ?>
        <?php
        $time_slot_id = $timeslot->getTimeSlotId($result['appointments']['appointment_time']);
        $doctor_id = $doctor->getDoctorId($result['appointments']['appointment_time'], $time_slot_id);

        if ($doctor_id == 1) {
            echo "<tr style=\"background-color: #CCFF99;\">";
        } else {
            echo "<tr style=\"background-color: #99CCFF;\">";
        }
        ?>
    <td><?php echo $count++; ?></td>
    <td><?php echo $this->Time->format('h:i A', $result['appointments']['appointment_time']); ?></td>
    <td><?php echo $result['appointments']['contact_name']; ?></td>
    <td><?php echo $result['appointments']['contact_phone']; ?></td>
    <td><?php echo $result['notifications']['description']; ?></td>
    <td><?php echo $result['appointments']['note']; ?></td>
    <td>
        <?php
        if (strcmp($result[0]['is_no_show'], '1') == 0) {
            echo '是';
        }
        ?>
    <td><?php echo $result['appointment_contacts']['contact_time']; ?></td>
    <td><?php echo $result['appointment_contacts']['contact_result']; ?></td>
    <td>
        <?php
        echo $this->Html->link('編輯', array('action' => 'edit', $result['appointments']['id']));
        ?>
    </td>
    <td>
        <?php
        echo $this->Form->postLink('刪除', array('action' => 'delete', $result['appointments']['id']), array('confirm' => '確定要刪除嗎?'));
        ?>
    </td>
    </tr>
<?php endforeach; ?>
</tbody>

</table>
<div class="span6">

</div>