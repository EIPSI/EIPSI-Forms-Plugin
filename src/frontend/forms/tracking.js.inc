        attachTracking( form ) {
            if ( ! window.EIPSITracking || ! form ) {
                return;
            }

            const formId = this.getFormId( form ) || 'default';

            window.EIPSITracking.registerForm( form, formId );
            form.dataset.trackingFormId = formId;

            const totalPages =
                parseInt( form.dataset.totalPages || '1', 10 ) || 1;
            const currentPageField = form.querySelector(
                '.eipsi-current-page'
            );
            const currentPage =
                parseInt(
                    currentPageField?.value || form.dataset.currentPage || '1',
                    10
                ) || 1;

            window.EIPSITracking.setTotalPages( formId, totalPages );
            window.EIPSITracking.setCurrentPage( formId, currentPage, {
                trackChange: false,
            } );
        },

