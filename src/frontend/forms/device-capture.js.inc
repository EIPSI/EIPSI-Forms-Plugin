        populateDeviceInfo( form ) {
            const deviceField = form.querySelector(
                '.eipsi-device-placeholder'
            );
            const browserField = form.querySelector(
                '.eipsi-browser-placeholder'
            );
            const osField = form.querySelector( '.eipsi-os-placeholder' );
            const screenField = form.querySelector(
                '.eipsi-screen-placeholder'
            );
            const startTimeField = form.querySelector( '.eipsi-start-time' );

            // Device type (mobile/tablet/desktop) - siempre se captura si el toggle device_type está ON
            if ( deviceField ) {
                deviceField.value = this.getDeviceType();
            }

            // Browser (nombre + versión) - solo si toggle browser está ON
            if ( browserField ) {
                browserField.value = this.getBrowser();
            }

            // OS (nombre + versión) - solo si toggle os está ON
            if ( osField ) {
                osField.value = this.getOS();
            }

            // Screen size (ancho x alto) - solo si toggle screen_width está ON
            if ( screenField && window.screen ) {
                const width = window.screen.width || '';
                const height = window.screen.height || '';
                screenField.value =
                    width && height ? `${ width }x${ height }` : width;
            }

            // Start timestamp - siempre se captura
            if ( startTimeField ) {
                startTimeField.value = Date.now();
            }
        },

        getDeviceType() {
            const ua =
                typeof navigator !== 'undefined' ? navigator.userAgent : '';
            if (
                /(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test( ua )
            ) {
                return 'tablet';
            }
            if (
                /Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(
                    ua
                )
            ) {
                return 'mobile';
            }
            return 'desktop';
        },

        getBrowser() {
            const ua =
                typeof navigator !== 'undefined' ? navigator.userAgent : '';
            let browser = 'Unknown';
            let version = '';

            // Detectar navegador y extraer versión mayor
            if ( ua.indexOf( 'Firefox' ) > -1 ) {
                browser = 'Firefox';
                const match = ua.match( /Firefox\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'SamsungBrowser' ) > -1 ) {
                browser = 'Samsung Browser';
                const match = ua.match( /SamsungBrowser\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'OPR' ) > -1 ) {
                browser = 'Opera';
                const match = ua.match( /OPR\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Opera' ) > -1 ) {
                browser = 'Opera';
                const match = ua.match( /Opera\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Edg/' ) > -1 ) {
                browser = 'Edge';
                const match = ua.match( /Edg\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Edge' ) > -1 ) {
                browser = 'Edge';
                const match = ua.match( /Edge\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Trident' ) > -1 ) {
                browser = 'Internet Explorer';
                const match = ua.match( /rv:(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Chrome' ) > -1 ) {
                browser = 'Chrome';
                const match = ua.match( /Chrome\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Safari' ) > -1 ) {
                browser = 'Safari';
                const match = ua.match( /Version\/(\d+)/ );
                version = match ? match[ 1 ] : '';
            }

            return version ? `${ browser } ${ version }` : browser;
        },

        getOS() {
            const ua =
                typeof navigator !== 'undefined' ? navigator.userAgent : '';
            let os = 'Unknown';
            let version = '';

            if ( ua.indexOf( 'Win' ) > -1 ) {
                os = 'Windows';
                const match = ua.match( /Windows NT (\d+\.\d+)/ );
                version = match ? match[ 1 ] : '';
            } else if ( ua.indexOf( 'Mac OS X' ) > -1 ) {
                os = 'macOS';
                const match = ua.match( /Mac OS X (\d+[_.]\d+)/ );
                version = match ? match[ 1 ].replace( '_', '.' ) : '';
            } else if ( ua.indexOf( 'X11' ) > -1 ) {
                os = 'UNIX';
            } else if ( ua.indexOf( 'Linux' ) > -1 ) {
                os = 'Linux';
            } else if ( /Android/.test( ua ) ) {
                os = 'Android';
                const match = ua.match( /Android (\d+(?:\.\d+)?)/ );
                version = match ? match[ 1 ] : '';
            } else if ( /iPhone|iPad|iPod/.test( ua ) ) {
                os = 'iOS';
                const match = ua.match( /OS (\d+_\d+)/ );
                version = match ? match[ 1 ].replace( '_', '.' ) : '';
            }

            return version ? `${ os } ${ version }` : os;
        },

