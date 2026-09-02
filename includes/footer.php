</main>

<?php 
$footerSponsors = function_exists('getActiveSponsors') ? getActiveSponsors() : [];
?>


<!-- Global JS -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>

</body>
</html>
