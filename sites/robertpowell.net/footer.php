<footer>
    <hr>
    <p><i class="fa-duotone fa-solid fa-copyright" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Robert Powell 2000 - <?= date('Y') ?></p>
</footer>
</div>


<!-- Start of Modal -->
<dialog id="myModal" class="modal" aria-labelledby="myModalTitle">
    <div class="modal-dialog modal-lg">

        <!-- Modal content-->    
        <div class="modal-content">
            <div class="modal-header d-block">
                <h3 class="modal-title text-black text-center" id="myModalTitle"><i class="fad fa-exclamation-triangle fa-2x" style="--fa-primary-color: black; --fa-secondary-color: #fa4a04; --fa-secondary-opacity: 1.0"></i> Robert Powell</h3>
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
</dialog> <!-- End of Modal-->

<!-- Page behaviour (ROP-348, 2026-11-06): plain JavaScript in place of jQuery + Bootstrap; the modal is a native <dialog> -->
<script src="<?= $asset('/scripts/site.js') ?>" defer nonce="<?= htmlspecialchars($csp_nonce) ?>"></script>

</body>

</html>
