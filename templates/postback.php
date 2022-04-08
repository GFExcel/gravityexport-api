<!doctype html>
<html lang="en">
<head>
    <title>Redirecting...</title>
</head>
<body>
</body>

<script type="text/javascript">
document.body.onload = function() {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = <?php echo json_encode($uri) ?>;

    <?php foreach($inputs as $name => $value): ?>

    var input = document.createElement('input');
    input.type="hidden";
    input.name = '<?php echo $name ?>';
    input.value = '<?php echo $value ?>';
    form.appendChild(input);
    <?php endforeach?>

    document.body.appendChild(form);

    form.submit();
}

</script>
</html>
