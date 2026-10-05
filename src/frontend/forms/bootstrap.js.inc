    // Inicializar consentimiento v2.5 al cargar DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initConsentV2);
    } else {
        initConsentV2();
    }

    EIPSIForms.init();

    window.EIPSIForms = EIPSIForms;
    window.EIPSIForms.conditionalNavigators = EIPSIForms.navigators;
} )();
