        initPagination( form ) {
            const allPages = Array.from(
                form.querySelectorAll( '.eipsi-page' )
            );
            const regularPages = [];
            let thankYouPage = null;

            if ( allPages.length > 0 ) {
                allPages.forEach( ( page ) => {
                    const type =
                        page.dataset.pageType ||
                        ( page.dataset.page === 'thank-you'
                            ? 'thank_you'
                            : 'standard' );

                    if ( type === 'thank_you' ) {
                        thankYouPage = page;
                        page.dataset.page = 'thank-you';
                        page.dataset.pageType = 'thank_you';
                        page.style.display = 'none';
                        page.setAttribute( 'aria-hidden', 'true' );
                        page.classList.add( 'eipsi-thank-you-page-block' );
                        return;
                    }

                    regularPages.push( page );
                    const assignedIndex = regularPages.length;
                    page.dataset.page = String( assignedIndex );

                    if ( assignedIndex === 1 ) {
                        page.style.display = '';
                        page.removeAttribute( 'aria-hidden' );
                    } else {
                        page.style.display = 'none';
                        page.setAttribute( 'aria-hidden', 'true' );
                    }
                } );

                const totalPages = regularPages.length || 1;
                form.dataset.totalPages = totalPages;
                form.dataset.hasThankYouPage = thankYouPage ? 'true' : 'false';

                const rawShowProgressPref = form.dataset.showProgressBar;
                const showProgressBar =
                    rawShowProgressPref === undefined ||
                    rawShowProgressPref === '' ||
                    rawShowProgressPref === 'true' ||
                    rawShowProgressPref === '1';

                const totalPagesField =
                    form.querySelector( '.form-progress .total-pages' ) ||
                    form.querySelector( '.total-pages' );
                if ( totalPagesField ) {
                    totalPagesField.textContent = totalPages;
                }

                const progressContainer =
                    form.querySelector( '.form-progress' );
                if ( progressContainer ) {
                    if ( ! showProgressBar ) {
                        progressContainer.style.display = 'none';
                    } else {
                        progressContainer.style.display =
                            totalPages > 1 ? '' : 'none';
                    }
                }

                const normalizedPage = this.getCurrentPage( form );
                this.setCurrentPage( form, normalizedPage, {
                    trackChange: false,
                } );
            }

            const prevButton = form.querySelector( '.eipsi-prev-button' );
            const nextButton = form.querySelector( '.eipsi-next-button' );
            const submitButton = form.querySelector( '.eipsi-submit-button' );

            if ( prevButton ) {
                prevButton.removeAttribute( 'disabled' );
                prevButton.addEventListener( 'click', ( e ) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (
                        form.dataset.submitting === 'true' ||
                        prevButton.disabled
                    ) {
                        return;
                    }
                    this.handlePagination( form, 'prev' );
                } );
            }

            if ( nextButton ) {
                nextButton.removeAttribute( 'disabled' );
                nextButton.addEventListener( 'click', ( e ) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (
                        form.dataset.submitting === 'true' ||
                        nextButton.disabled
                    ) {
                        return;
                    }
                    this.handlePagination( form, 'next' );
                } );
            }

            if ( submitButton ) {
                submitButton.removeAttribute( 'disabled' );
            }

            const currentPage = this.getCurrentPage( form );
            const totalPages = this.getTotalPages( form );
            this.updatePaginationDisplay( form, currentPage, totalPages );
        },

