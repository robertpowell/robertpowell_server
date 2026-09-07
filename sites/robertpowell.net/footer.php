<footer>
    <hr>
    <p><i class="fa-duotone fa-solid fa-copyright" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;" <i class="fa-duotone fa-solid fa-phone"></i> Robert Powell 2000 - <?= date('Y') ?></p>
</footer>
</div>


<!-- Start of Modal -->
<div id="myModal" class="modal fade" role="dialog">
    <div class="modal-dialog modal-lg">

        <!-- Modal content-->    
        <div class="modal-content">
            <div class="modal-header d-block">
                <h3 class="modal-title text-black text-center"><i class="fad fa-exclamation-triangle fa-2x" style="--fa-primary-color: black; --fa-secondary-color: #fa4a04; --fa-secondary-opacity: 1.0"></i> Robert Powell</h3>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><i class="fal fa-times fa-2x text-white"></i></button>
            </div>
            <div id="modalbody" class="modal-body">
				<p class="font-weight-bold">Update July 2026</p>
                <p>There are no current updates to report.</p>
					</div>
                <div class="text-center">
                    <p>If you need to contact me you can do so using the details delow.</p>
                    <h4><i class="fa-duotone fa-solid fa-phone" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;" ></i><a href="tel:+447970123407"> +44 7970 123 407</a></h4>
                    <div class="d-block d-sm-none">(Tap to dial)</div>
		<h4><i class="fa-duotone fa-solid fa-envelope" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> <a href="mailto:mail@robertpowell.net?subject=Message from robertpowell.net">mail@robertpowell.net</a></h4>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-info" style="background-color: #fa4a04; border-color: #fa4a04;" data-dismiss="modal">Close</button>
                </div>
            </div>

        </div>


    </div>
</div> <!-- End of Modal-->

<!-- Bootstrap scripts -->

<script nonce="<?= htmlspecialchars($csp_nonce) ?>" type="text/javascript" src="/scripts/jquery-3.5.1.min.js" nonce="<?= htmlspecialchars($csp_nonce) ?>"></script>
<script nonce="<?= htmlspecialchars($csp_nonce) ?>" type="text/javascript" src="/scripts/bootstrap.bundle.min.js" ></script>

<?php
// get the name of the page - used in the modal decsision and in setting the active class for the navbar
$cfn = basename($_SERVER['PHP_SELF'],".php");
// set a var to check the page and decide to trigger the modal.
echo '<script type="text/javascript" nonce="' . htmlspecialchars($csp_nonce) . '">var pagename="';
echo $cfn;
echo '";</script>';
//echo $cfn."\n";
?>

<!-- On-load script to launch the modal
<script type="text/javascript" nonce="<?= htmlspecialchars($csp_nonce) ?>">

    $(window).on('load', function() {
        if (pagename == 'index') {
            $('#myModal').modal('show');
        }
    });
</script> -->

<!-- Civid-19 modal trigger (not on load)-->
<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
    // open modal on loading
    $(document).ready(function() {


         $("#covid19").click(function() {
         $("#myModal").modal('show');
        });

        // close modal on ESC key
        $(document).keydown(function(event) {
            if (event.keyCode == 27) {
                $('#myModal').modal('hide');
            }
        });

        // change the screen to high contrast
        $("#colortoggle").click(function() {
            $("body, #header").toggleClass('bg-dark'),
                $("body, #header").toggleClass('text-white'),
                $("#modalbody").toggleClass('text-light').toggleClass('bg-dark');
        });
        //make text larger
        $("#bigger").click(function() {
            curSize = parseInt($('#main').css('font-size')) + 2;
            mcurSize = parseInt($('#modalbody').css('font-size')) + 2;
            if (curSize <= 30)
                $('#main').css('font-size', curSize);
            $('#modalbody').css('font-size', curSize);
        });
        //make text smaller
        $("#smaller").click(function() {
            curSize = parseInt($('#main').css('font-size')) - 2;
            mcurSize = parseInt($('#modalbody').css('font-size')) - 2;
            if (curSize >= 10)
                $('#main').css('font-size', curSize);
            $('#modalbody').css('font-size', curSize);
        });
        //page reload
        $("#reload").click(function() {
            location.reload();
        });
    });

$('#gear, #globe').hover(
       function(){ $(this).addClass('fa-spin-pulse') },
       function(){ $(this).removeClass('fa-spin-pulse') }
)

$('#location, #contact, #phone').hover(
       function(){ $(this).addClass('fa-bounce') },
       function(){ $(this).removeClass('fa-bounce') }
)

</script>




<?php
// Adding thecose here to determine which page has loaded and add thr 'active' class to the correct.
echo '<script type="text/javascript" nonce="' . htmlspecialchars($csp_nonce) . '">$("#';
echo $cfn;
echo '").addClass("active");</script>';

//Note to add Page counting code here.



?>

</body>

</html>
