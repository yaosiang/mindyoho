<?php
ob_clean();

$date = new DateTime($appointment_time);
if ($date->format('N') == 1) {
    $weekday = '一';
} elseif ($date->format('N') == 2) {
    $weekday = '二';
} elseif ($date->format('N') == 3) {
    $weekday = '三';
} elseif ($date->format('N') == 4) {
    $weekday = '四';
} elseif ($date->format('N') == 5) {
    $weekday = '五';
} elseif ($date->format('N') == 6) {
    $weekday = '六';
} else {
    $weekday = '日';
}

?>
<div class="row">
    心悠活診所預約回診單<br />
    ============================
    <div class="span6">
        <table class="table">
            <tbody>
                <tr>
                    <td>掛號證號：<?php echo $serial_number; ?></td>
                </tr>
                <tr>
                    <td>姓名：<?php echo $name; ?></td>
                </tr>
                <tr>
                    <td>回診日期：<?php echo $this->Time->format('Y', $appointment_time) . ' 年 ' . $this->Time->format('m', $appointment_time) . ' 月 ' . $this->Time->format('d', $appointment_time) . ' 日 星期' . $weekday ?></td>
                </tr>
                <tr>
                    <td>回診時間：<?php echo $this->Time->format('G:i', $appointment_time); ?></td>                    
                </tr>
                <tr><td></td></tr>
                <tr>
                    <td>門診醫師：<?php echo $doctor; ?></td>
                </tr>
                <tr>
                    <td>地址：台南市北區金華路五段14號</td>
                </tr>
                <tr>
                    <td>預約電話：06-2236766</td>
                </tr>		
            </tbody>
        </table>
    </div>
    ============================
    <div class="span6">
    </div>
</div>