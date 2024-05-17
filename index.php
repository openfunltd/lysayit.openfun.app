<?php

$domain = 'ly.govapi.tw';
if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
    if (getenv('API_URL')) {
        $domain = getenv('API_URL');
    }
}
$doc_id = $_GET['doc_id'] ?? '1133001_00006';
$url = sprintf("https://%s/gazette_agenda/%s/html?parse=1", $domain, $doc_id);
$data = file_get_contents($url);
$agenda_obj = json_decode($data);
if (!$agenda_obj->blocks ?? false) {
    echo "No data found";
    printf("<a href=\"%s\">API</a>", htmlspecialchars($url));
    exit;
}
$legislators = [];
$voters = [];
if (preg_match('#第(\d+)屆#', $agenda_obj->title, $matches)) {
    $url = sprintf("https://%s/legislator?term=%d&limit=200", $domain, $matches[1]);
    $legislator_obj = json_decode(file_get_contents($url));
    foreach ($legislator_obj->legislators as $legislator) {
        if ($legislator->leaveDate and strtotime($legislator->leaveDate) < strtotime($agenda_obj->date)) {
            continue;
        }

        $voter = new StdClass;
        $voter->name = $legislator->name;
        $voter->party = $legislator->party;
        $voter->caucus = $legislator->partyGroup;
        $voter->photo = $legislator->picUrl;
        $voters[] = $voter;

        $legislators[$legislator->name] = $legislator;
    }
}

$messages = [];
$showed_speakers = [];
$sections = [];
$votes = [];
foreach ($agenda_obj->votes as $vote) {
    $votes[$vote->line_no] = $vote;
}
foreach ($agenda_obj->blocks as $idx => $blocks) {
    $text = $blocks[0];
    $lineno = $agenda_obj->block_lines[$idx];

    if (strpos($text, '：') === false) {
        $messages[] = [
            'type' => 'info',
            'content' => implode("\n", $blocks),
            'class' => '',
            'photo' => '',
            'lineno' => $lineno,
        ];
        continue;
    }

    $default_photo = 'https://bootdey.com/img/Content/avatar/avatar6.png';
    $content = implode("\n", $blocks);
    list($speaker, $content) = explode('：', $content, 2);

    if ($speaker == '主席' or strpos($speaker, '委員') !== false) {
        $class = "ks-self";
        if ($speaker == '主席') {
            $name = $agenda_obj->{'主席'};
        } else {
            $name = $speaker;
        }
        $name = str_replace('委員', '', $name);
        $name = str_replace('院長', '', $name);
        if (isset($legislators[$name])) {
            $photo = $legislators[$name]->picUrl;
            $bioId = $legislators[$name]->bioId;
            if (!array_key_exists($name, $showed_speakers)) {
                $showed_speakers[$name] = count($showed_speakers);
            }
        } else {
            $photo = $default_photo;
            $bioId = '';
        }
    } elseif ($speaker == '表決結果名單') {
        if (isset($votes[$lineno])) {
            $vote = $votes[$lineno];
            $sections[] = [
                'title' => '表決結果名單:' . $vote->{'表決議題'},
                'description' => sprintf("出席人數：%d 贊成人數：%d 反對人數：%d",
                    $vote->{'表決結果'}->{'出席人數'},
                    $vote->{'表決結果'}->{'贊成人數'},
                    $vote->{'表決結果'}->{'反對人數'}),
                'lineno' => $lineno,
            ];
            $messages[] = [
                'type' => 'vote',
                'content' => $vote,
                'lineno' => $lineno,
            ];
            continue;
        }
        
    } elseif ($speaker == '段落') {
        if (strpos($content, '議事錄：') === 0) {
            $sections[] = [
                'title' => explode("\n", $content)[0],
                'description' => '',
                'lineno' => $lineno,
            ];
            $messages[] = [
                'type' => 'info',
                'content' => $content,
                'class' => '',
                'photo' => '',
                'lineno' => $lineno,
            ];
        } else {
            $sections[] = [
                'title' => $content,
                'description' => '',
                'lineno' => $lineno,
            ];
            $messages[] = [
                'type' => 'section',
                'content' => $content,
                'class' => '',
                'photo' => '',
                'lineno' => $lineno,
            ];
        }
        continue;
    } else {
        $class = "ks-from";
        $photo = $default_photo;
        $bioId = '';
    }
    $messages[] = [
        'type' => 'message',
        'speaker' => $speaker,
        'content' => $content,
        'class' => $class,
        'photo' => $photo,
        'bioId' => $bioId,
        'lineno' => $lineno,
    ];
}

?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($agenda_obj->title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-v4-rtl/4.1.1-1/css/bootstrap.min.css" integrity="sha512-iqRdf+0KMFmNZgdsA+8bz1MWIIXQBUCavPYVSVI83fcVfH2Y2PnNooLN04bgTNoUiQvIzidiIHJAcIP/uAEV9w==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.2.0/crypto-js.min.js" integrity="sha512-a+SUDuwNzXDvz4XrIcXHuCf089/iJAoN4lmrXJg18XnduKK6YlDHNRalv4yd1N40OKI80tFidF+rqTFKGPoWFQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.2.0/md5.min.js" integrity="sha512-ENWhXy+lET8kWcArT6ijA6HpVEALRmvzYBayGL6oFWl96exmq8Fjgxe2K6TAblHLP75Sa/a1YjHpIZRt+9hGOQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="//code.jquery.com/jquery-1.10.2.min.js"></script>
<script src="//d3js.org/d3.v3.min.js"></script>
<script src="lyvote.js"></script>
</head>
<body>
<div class="container-fluid">
<div class="ks-page-content">
<div class="ks-page-content-body">
<div class="ks-messenger">
<div class="ks-discussions">
<div class="ks-search">
<div class="input-icon icon-right icon icon-lg icon-color-primary">
<span class="icon-addon">
<span class="la la-search"></span>
</span>
</div>
</div>
<div class="ks-body" data-auto-height style="overflow-y: auto; padding: 0px; width: 339px;" tabindex="0">
<div class="jspContainer" style="width: 339px; height: 550px;">
<div class="jspPane" style="padding: 0px; top: 0px; width: 329px;">
    <ul class="ks-items">
        <!--
<li class="ks-item ks-active">
<a href="#">
<span class="ks-group-amount">3</span>
<div class="ks-body">
<div class="ks-name">
Group Chat
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">
<img src="https://bootdey.com/img/Content/avatar/avatar1.png" width="18" height="18" class="rounded-circle"> The weird future of movie theater food
</div>
</div>
</a>
</li>
<li class="ks-item ks-unread">
<a href="#">
<span class="ks-group-amount">5</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">
<img src="https://bootdey.com/img/Content/avatar/avatar2.png" width="18" height="18" class="rounded-circle"> Why didn't he come and talk to me...
</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar3.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar4.png" width="36" height="36">
<span class="badge badge-pill badge-danger ks-badge ks-notify">7</span>
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar5.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar6.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar7.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar ks-online">
<img src="https://bootdey.com/img/Content/avatar/avatar1.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Brian Diaz
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">The weird future of movie theater food</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-group-amount">3 <span class="badge badge-pill badge-danger ks-badge ks-notify">7</span></span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar ks-offline">
<img src="https://bootdey.com/img/Content/avatar/avatar2.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar3.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar4.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Eric George
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">Why didn't he come and talk to me himse...</div>
</div>
</a>
</li>
<li class="ks-item">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar5.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Lauren Sandoval
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">The weird future of movie theater food</div>
</div>
</a>
</li>
<li class="ks-item ks-closed">
<a href="#">
<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar6.png" width="36" height="36">
</span>
<div class="ks-body">
<div class="ks-name">
Brian Diaz
<span class="ks-datetime">just now</span>
</div>
<div class="ks-message">The weird future of movie theater food</div>
</div>
</a>
</li>
-->
<?php foreach ($sections as $section) { ?>
<li class="ks-item ks-closed">
<a href="#message-<?= $section['lineno'] ?>">
    <!--<span class="ks-avatar">
<img src="https://bootdey.com/img/Content/avatar/avatar6.png" width="36" height="36">
</span>-->
<div class="ks-body">
    <div class="ks-name">
        <?= htmlspecialchars($section['title']) ?>
        <!--<span class="ks-datetime">just now</span>-->
    </div>
    <div class="ks-message"><?= htmlspecialchars($section['description']) ?></div>
</div>
</a>
</li>
<?php } ?>
</ul>
</div>
<div class="jspVerticalBar">
<div class="jspCap jspCapTop"></div>
<div class="jspTrack" style="height: 550px;">
<div class="jspDrag" style="height: 261px;">
<div class="jspDragTop"></div>
<div class="jspDragBottom"></div>
</div>
</div>
<div class="jspCap jspCapBottom"></div>
</div>
</div>
</div>
</div>
<div class="ks-messages ks-messenger__messages">
<div class="ks-header">
<div class="ks-description">
    <div class="ks-name"><?= htmlspecialchars($agenda_obj->title) ?></div>
    <div class="ks-amount">
        <?= htmlspecialchars($agenda_obj->{'時間'}) ?>
        @ <?= htmlspecialchars($agenda_obj->{'地點'}) ?>
        (主席：<?= htmlspecialchars($agenda_obj->{'主席'}) ?>)
    </div>
</div>
<div class="ks-controls">
<div class="dropdown">
<button class="btn btn-primary-outline ks-light ks-no-text ks-no-arrow" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
<span class="la la-ellipsis-h ks-icon"></span>
</button>
<div class="dropdown-menu dropdown-menu-right ks-simple" aria-labelledby="dropdownMenuButton">
    <!--
<a class="dropdown-item" href="#">
<span class="la la-user-plus ks-icon"></span>
<span class="ks-text">Add members</span>
</a>
<a class="dropdown-item" href="#">
<span class="la la-eye-slash ks-icon"></span>
<span class="ks-text">Mark as unread</span>
</a>
<a class="dropdown-item" href="#">
<span class="la la-bell-slash-o ks-icon"></span>
<span class="ks-text">Mute notifications</span>
</a>
<a class="dropdown-item" href="#">
<span class="la la-mail-forward ks-icon"></span>
<span class="ks-text">Forward</span>
</a>
<a class="dropdown-item" href="#">
<span class="la la-ban ks-icon"></span>
<span class="ks-text">Spam</span>
</a>
<a class="dropdown-item" href="#">
<span class="la la-trash-o ks-icon"></span>
<span class="ks-text">Delete</span>
</a>
-->
    這裡放一些可以連回去原資料的功能
</div>
</div>
</div>
</div>
<div class="ks-body" data-auto-height data-reduce-height=".ks-footer" data-fix-height="32" style="height: 480px; overflow-y: auto; padding: 0px; width: 701px;" tabindex="0">
    <div class="jspContainer" style="width: 701px">
<div class="jspPane" style="padding: 0px; top: 0px; width: 691px;">
    <ul class="ks-items">
        <?php foreach ($messages as $message) { ?>
            <?php if ($message['type'] == 'info') { ?>
            <li class="ks-item" id="message-<?= $message['lineno'] ?>"><!-- message -->
                <div class="ks-body">
                    <div class="ks-header">
                        <span class="ks-datetime"><!-- TODO: time --></span>
                    </div>
                    <div class="ks-message"><?= nl2br(htmlspecialchars($message['content'])) ?></div>
                </div>
            </li>
            <?php } elseif ($message['type'] == 'section') { ?>
            <li class="ks-item" id="message-<?= $message['lineno'] ?>"><!-- message -->
                <h3><?= nl2br(htmlspecialchars($message['content'])) ?></h3>
            </li>
            <?php } elseif ($message['type'] == 'vote') { ?>
                <?php $vote = $message['content']; ?>
            <li class="ks-item" id="message-<?= $message['lineno'] ?>"><!-- message -->
            <h4><?= htmlspecialchars($vote->{'表決議題'}) ?></h4>
            </li>
            <li>
            <div class="ks-message">
                <p>表決時間：<?= htmlspecialchars($vote->{'表決時間'}) ?></p>
                <p>表決型態：<?= htmlspecialchars($vote->{'表決型態'}) ?></p>
                <p>
                <?= htmlspecialchars($vote->{'表決結果'}->{'出席人數'}) ?>人出席
                ，贊成<?= htmlspecialchars($vote->{'表決結果'}->{'贊成人數'}) ?>人
                ，反對<?= htmlspecialchars($vote->{'表決結果'}->{'反對人數'}) ?>人
                ，棄權<?= htmlspecialchars($vote->{'表決結果'}->{'棄權人數'}) ?>人
                </p>
            </div>
            </li>
            <li>
            <div id="vote-result-<?= $message['lineno'] ?>" class="twlyvote ad-8 session-2 sitting-13" style="width: 100%; height: 400px">
                <span class="approval"><?= implode(';', $vote->{'贊成'} ?? []) ?></span>
                <span class="veto"><?= implode(';', $vote->{'反對'} ?? []) ?></span>
                <span class="abstention"><?= implode(';', $vote->{'棄權'} ?? []) ?></span>
            </div>
<script>
          lyvote.render({
              transform: "scale(0.65)",
              seatMapping: lyvote.map.linear,
              node: '#vote-result-<?= $message['lineno'] ?>',
              voter: <?= json_encode($voters) ?>,
          });
</script>
            </li>
            <?php } elseif ($message['type'] == 'message') { ?>
            <li class="ks-item <?= $message['class'] ?>" id="message-<?= $message['lineno'] ?>"><!-- message -->
                <?php if ($message['photo']) { ?>
                <span class="ks-avatar ks-offline speaker-photo" data-bioid="<?= $message['bioId'] ?>">
                    <img src="<?= htmlspecialchars($message['photo']) ?>" width="36" height="36" class="rounded-circle">
                </span>
                <?php } ?>
                <div class="ks-body">
                    <div class="ks-header">
                        <span class="ks-name"><?= htmlspecialchars($message['speaker']) ?></span>
                        <span class="ks-datetime"><!-- TODO: time --></span>
                    </div>
                    <div class="ks-message"><?= nl2br(htmlspecialchars($message['content'])) ?></div>
                </div>
            </li>
            <?php } ?>
        <?php } ?>
    </ul>
</div>
<div class="jspVerticalBar">
<div class="jspCap jspCapTop"></div>
<div class="jspTrack" style="height: 481px;">
<div class="jspDrag" style="height: 206px;">
<div class="jspDragTop"></div>
<div class="jspDragBottom"></div>
</div>
</div>
</div>
</div>
</div>
</div>
<div class="ks-info ks-messenger__info">
<div class="ks-header">
User Info
</div>
<?php foreach ($showed_speakers as $name => $seq) { ?>
<?php $legislator = $legislators[$name]; ?>
<div class="ks-body speaker-list" id="bioinfo-<?= $legislator->bioId ?>"
    <?php if ($seq) { ?> style="display: none;" <?php } ?>
    >
<div class="ks-item ks-user">
<span class="ks-avatar ks-online">
    <img src="<?= htmlspecialchars($legislator->picUrl) ?>" width="36" height="36" class="rounded-circle">
</span>
<span class="ks-name"><?= htmlspecialchars($legislator->name) ?></span>
</div>
<?php foreach ([
    'sex' => '性別',
    'party' => '黨籍',
    'areaName' => '選區',
] as $key => $label) { ?>
<div class="ks-item">
    <div class="ks-name"><?= $label ?></div>
    <div class="ks-text"><?= htmlspecialchars($legislator->$key) ?></div>
</div>
<?php } ?>
</div>
<?php } ?>
<div class="ks-footer"></div>
</div>
</div>
</div>
</div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-v4-rtl/4.1.1-1/js/bootstrap.bundle.min.js" integrity="sha512-5LQcfkpkY3H6HduPyJ9ogeSCnwucwzTdYC/cFvk+SHdFrsfIXtlhaJRwf3G551XNHW+WTUEIWDgGX+a4kchU2w==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script type="text/javascript">
$('.speaker-photo').click(function() {
    var bioId = $(this).data('bioid');
    $('.speaker-list').hide();
    $('#bioinfo-' + bioId).show();
});

</script>
</body>
</html>
