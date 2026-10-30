/* Generated from includes/i18n/content-defaults.json. */
globalThis.EIPSIContentCatalog={
  "en": {
    "flow": {
      "pages": [],
      "allow_back": true,
      "navigation_labels": {
        "back": "Back",
        "next": "Next",
        "finish": "Finish"
      },
      "progress": "pages",
      "completion": {
        "title": "Thank you for responding",
        "message_html": "<p>Thank you for completing the assessment.</p>",
        "image_url": "",
        "button_label": "",
        "allow_restart": true
      },
      "save_continue": {
        "enabled": false,
        "window": {
          "value": 7,
          "unit": "days"
        }
      },
      "metadata_profile": "basic",
      "messages": {
        "expired": "Your previous session expired. Start again from the first page.",
        "expired_saved": "Partial responses from your previous session were saved. Start again from the first page.",
        "processing": "Updating session…",
        "updated": "Session updated.",
        "completed": "Responses saved successfully.",
        "invalid": "Your responses could not be processed. Please try again.",
        "delivery_error": "Your responses could not be confirmed as saved. Please try again using the same button.",
        "discard_accept": "Accept",
        "discard_cancel": "Cancel",
        "discard_label": "Discard response",
        "discard_confirm": "Discard this response? The session and responses for this assessment will be deleted so you can start again.",
        "discarded": "Response discarded. You can start again.",
        "progress": "Page [ACTUAL] of [TOTAL]",
        "progress_accessible": "Page progress"
      }
    },
    "flow_messages": {
      "expired": "Your previous session expired. Start again from the first page.",
      "expired_saved": "Partial responses from your previous session were saved. Start again from the first page.",
      "processing": "Updating session…",
      "updated": "Session updated.",
      "completed": "Responses saved successfully.",
      "invalid": "Your responses could not be processed. Please try again.",
      "delivery_error": "Your responses could not be confirmed as saved. Please try again using the same button.",
      "discard_accept": "Accept",
      "discard_cancel": "Cancel",
      "discard_label": "Discard response",
      "discard_confirm": "Discard this response? The session and responses for this assessment will be deleted so you can start again.",
      "discarded": "Response discarded. You can start again.",
      "progress": "Page [ACTUAL] of [TOTAL]",
      "progress_accessible": "Page progress"
    },
    "initial": {
      "contract": "INITIAL_SCREEN_V2",
      "title_html": "<h1>Join the study</h1>",
      "description_html": "",
      "footer_html": "",
      "background": "#ffffff",
      "text_color": "#243247",
      "max_width": 720,
      "authentication": {
        "type": "EMAIL",
        "label": "Email",
        "label_html": "",
        "label_visible": true,
        "placeholder": "name@example.com",
        "button": "Continue",
        "verification": "NEVER",
        "confirm_after_first": true,
        "membership_enabled": false,
        "membership_label": "I already participate in this study"
      },
      "withdrawal": {
        "label": "Leave study",
        "position": "BOTTOM",
        "align": "RIGHT",
        "text_color": "#536176",
        "background": "#ffffff",
        "border_color": "#d5dce5",
        "border_width": 1,
        "radius": 6,
        "font_size": 13,
        "modal_title": "Leave study",
        "modal_html": "<p>Choose what should happen to your data.</p>",
        "retain_label": "Leave the study and allow my data to be used",
        "delete_label": "Leave the study and request deletion of my data",
        "confirm_label": "Confirm withdrawal",
        "modal_title_html": "",
        "typed_confirmation": true,
        "retain_background": "#edf3fa",
        "retain_text_color": "#243247",
        "delete_background": "#fff1f1",
        "delete_text_color": "#8a2424",
        "confirmation": {
          "title_html": "<h2>Confirm decision</h2>",
          "message_html": "<p>Are you sure you want to [opcion]?</p>",
          "instruction_html": "<p>To confirm, enter the following text:</p>",
          "body_html": "<p>Are you sure you want to [OPCION]? To confirm, enter <strong>[CONFIRMACION]</strong>.</p>",
          "input_label": "Confirmation field",
          "input_placeholder": "Enter the indicated text in uppercase",
          "cancel_label": "Cancel",
          "background": "#ffffff",
          "text_color": "#243247",
          "max_width": 720,
          "buttons_layout": "ROW"
        },
        "back_label": "Back",
        "completion_html": "<h2>Your participation has ended</h2><p>Your decision was recorded: <strong>[OPCION]</strong>. Thank you for participating.</p>"
      },
      "templates": {
        "VERIFY_PARTICIPANT": {
          "subject": "Confirm your email",
          "html": "<h1>Confirm your email</h1><p>[study_name]</p><p>Confirm your address to receive upcoming notifications.</p><p>[assessment_name]</p><p>Available: [available_time]</p><p>Due: [due_time]</p><p><a href=\"[direct_link]\">Continue</a></p><hr><p><a href=\"[withdrawal_link]\">Leave study</a></p>"
        },
        "ASSESSMENT_AVAILABLE": {
          "subject": "Assessment available",
          "html": "<h1>Assessment available</h1><p>[study_name]</p><p>Your next assessment is available.</p><p>[assessment_name]</p><p>Available: [available_time]</p><p>Due: [due_time]</p><p><a href=\"[direct_link]\">Continue</a></p><hr><p><a href=\"[withdrawal_link]\">Leave study</a></p>"
        },
        "ASSESSMENT_REMINDER": {
          "subject": "Assessment reminder",
          "html": "<h1>Assessment reminder</h1><p>[study_name]</p><p>You can still complete your assessment.</p><p>[assessment_name]</p><p>Available: [available_time]</p><p>Due: [due_time]</p><p><a href=\"[direct_link]\">Continue</a></p><hr><p><a href=\"[withdrawal_link]\">Leave study</a></p>"
        }
      }
    },
    "confirmation": {
      "title_html": "<h2>Confirm decision</h2>",
      "message_html": "<p>Are you sure you want to [opcion]?</p>",
      "instruction_html": "<p>To confirm, enter the following text:</p>",
      "body_html": "<p>Are you sure you want to [OPCION]? To confirm, enter <strong>[CONFIRMACION]</strong>.</p>",
      "input_label": "Confirmation field",
      "input_placeholder": "Enter the indicated text in uppercase",
      "cancel_label": "Cancel",
      "background": "#ffffff",
      "text_color": "#243247",
      "max_width": 720,
      "buttons_layout": "ROW"
    },
    "emails": {
      "VERIFY_PARTICIPANT": {
        "subject": "Confirm your email",
        "html": "<h1>Confirm your email</h1><p>[study_name]</p><p>Confirm your address to receive upcoming notifications.</p><p>[assessment_name]</p><p>Available: [available_time]</p><p>Due: [due_time]</p><p><a href=\"[direct_link]\">Continue</a></p><hr><p><a href=\"[withdrawal_link]\">Leave study</a></p>"
      },
      "ASSESSMENT_AVAILABLE": {
        "subject": "Assessment available",
        "html": "<h1>Assessment available</h1><p>[study_name]</p><p>Your next assessment is available.</p><p>[assessment_name]</p><p>Available: [available_time]</p><p>Due: [due_time]</p><p><a href=\"[direct_link]\">Continue</a></p><hr><p><a href=\"[withdrawal_link]\">Leave study</a></p>"
      },
      "ASSESSMENT_REMINDER": {
        "subject": "Assessment reminder",
        "html": "<h1>Assessment reminder</h1><p>[study_name]</p><p>You can still complete your assessment.</p><p>[assessment_name]</p><p>Available: [available_time]</p><p>Due: [due_time]</p><p><a href=\"[direct_link]\">Continue</a></p><hr><p><a href=\"[withdrawal_link]\">Leave study</a></p>"
      }
    },
    "authoring": {
      "title": "New assessment",
      "question": "Write your question",
      "instruction": "Write the instructions for this assessment.",
      "option": [
        "Option 1",
        "Option 2"
      ],
      "likert": [
        "Very poor",
        "Poor",
        "Neutral",
        "Good",
        "Very good"
      ],
      "rows": [
        "Item 1",
        "Item 2"
      ],
      "row": "New item",
      "labels": {
        "text": "Text",
        "textarea": "Long text",
        "email": "Email",
        "number": "Number",
        "tel": "Phone",
        "date": "Date",
        "url": "URL",
        "single_choice": "Single choice",
        "multiple_choice": "Multiple choice",
        "select": "Dropdown",
        "likert": "Likert",
        "vas": "VAS",
        "matrix": "Likert matrix",
        "instruction": "Text / instructions",
        "image": "Image",
        "group": "Group / section",
        "randomization": "Randomization",
        "page_break": "Salto de página"
      },
      "study_title": "Study title",
      "text": "Text",
      "new_option": "New option",
      "new_label": "New label",
      "new_block": "New block",
      "block_selection": "Block selection",
      "assessment_selection": "Assessment selection",
      "study_label": "Study",
      "group": "Group",
      "assessment": "Assessment",
      "template_names": {
        "VERIFY_PARTICIPANT": "Email validation",
        "ASSESSMENT_AVAILABLE": "Assessment available",
        "ASSESSMENT_REMINDER": "Reminder"
      }
    },
    "question_messages": {
      "required_message": "Answer this question to continue.",
      "length_message": "The response must contain between [MINIMO] - [MAXIMO] characters.",
      "number_range_message": "The response must be between [MINIMO] - [MAXIMO].",
      "selection_min_message": "Select at least [MINIMO] options.",
      "selection_max_message": "Select at most [MAXIMO] options.",
      "invalid_value_message": "Select an available option.",
      "unanswered_value_message": "Unanswered",
      "matrix_heading": "Item",
      "empty_image_message": "Image not configured",
      "date_placeholder": "YYYY-MM-DD",
      "date_open_label": "Open calendar",
      "date_close_label": "Close calendar",
      "date_previous_label": "Previous month",
      "date_next_label": "Next month",
      "date_month_names": "January|February|March|April|May|June|July|August|September|October|November|December",
      "date_weekday_names": "Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday"
    },
    "validation": {
      "text": "Enter valid text.",
      "email": "Enter a valid email address.",
      "url": "Enter a valid full URL.",
      "letters": "Use only letters and spaces.",
      "number": "Enter a number within the configured range.",
      "integer": "Enter a whole number.",
      "date": "Enter a valid date.",
      "vas": "Select a valid value on the scale.",
      "multiple_choice": "Select available options without duplicates."
    },
    "question_defaults": {
      "text": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "free",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "textarea": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "free",
        "display_mode": "multiline",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0,
        "rows": 4
      },
      "email": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "email",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "number": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "integer_only": false,
        "placeholder": "",
        "min": null,
        "max": null,
        "step": 1
      },
      "tel": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "tel",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "date": {
        "label_html": null,
        "helper_html": null,
        "footer_html": ""
      },
      "url": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "url",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "single_choice": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "columns": 1
      },
      "multiple_choice": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "columns": 1,
        "min_selected": 0,
        "max_selected": null
      },
      "select": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "placeholder": "Select an option"
      },
      "likert": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "min": 1,
        "preset": "custom",
        "reversed": false,
        "gradient": "none",
        "gradient_start": "#f44336",
        "gradient_end": "#4caf50"
      },
      "vas": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "gradient": "none",
        "gradient_start": "#f44336",
        "gradient_end": "#4caf50",
        "labels": [
          "Not at all",
          "Very much"
        ],
        "label_font_size": 16,
        "label_scale": 100,
        "value_font_size": 36,
        "bold_labels": true,
        "label_boxes": false,
        "label_alignment": 100,
        "value_position": "below"
      },
      "matrix": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "matrix_kind": "likert",
        "matrix_rows": [],
        "presentation": "horizontal",
        "min": 1,
        "preset": "custom",
        "reversed": false,
        "gradient": "none",
        "gradient_start": "#f44336",
        "gradient_end": "#4caf50"
      }
    },
    "vas_endpoints": [
      "Not at all",
      "Very much"
    ],
    "study": {
      "completion_title": "Thank you for participating",
      "completion_message": "Your responses have been saved."
    },
    "public_messages": {
      "email_not_found": "We could not find that email in this study. Check the address or uncheck “[PERTENENCIA]” to enroll.",
      "email_registered": "That email is already registered.",
      "email_validation_requested": "If applicable, we sent you a link to validate your email.",
      "email_first_link": "We emailed you access to the assessment.",
      "email_validation_sent": "We sent you a link to validate your email.",
      "email_verified": "Email validated.",
      "email_future_notices": "You will receive email notifications of upcoming assessments.",
      "email_required": "Enter your email.",
      "email_invalid": "Enter a valid email address.",
      "review_title": "Confirm your email",
      "review_body": "Your first assessment was saved. Enter your address again. If there was a typo, you can correct it without losing your responses.",
      "review_label": "Email",
      "review_button": "Confirm address",
      "review_link_button": "Send validation link",
      "review_pending": "We requested a validation email to [EMAIL]. Open it to confirm the address. You can also correct it and request another link.",
      "review_sent": "We sent the validation link. Open it to confirm your address and access upcoming assessments.",
      "review_done": "Address confirmed.",
      "change_title": "Confirm email change",
      "change_body": "The email differs from the address entered previously. Are you sure you want to update it?",
      "change_cancel": "Cancel",
      "change_accept": "Update email",
      "withdrawal_invalid": "Enter exactly the indicated name in uppercase.",
      "withdrawal_input_label": "Confirmation field",
      "withdrawal_no_script": "Enable JavaScript to use the typed withdrawal confirmation.",
      "wait_title": "No assessments are available.",
      "next_time": "Next availability:",
      "finished_body": "Participation has ended.",
      "terminated_body": "Participation has ended due to a study condition.",
      "finalized_body": "The study has ended.",
      "conflict": "The assessment changed in another tab. Review the updated state.",
      "rate_limit": "Wait a moment before trying again.",
      "change_unconfirmed": "The email differs from the address entered previously. Confirm that you want to update it.",
      "unique_response": "A response from this IP has already been recorded for this study.",
      "email_collision": "That email already belongs to another participation. Your responses were not merged; check the address or use another one.",
      "invalid_fields": "Check the entered data before continuing.",
      "operation_failed": "We could not save or load the assessment. Please try again.",
      "unavailable_title": "Assessment unavailable",
      "content_failed": "We could not load the published content.",
      "document_title": "Assessment",
      "assessment_expired": "The period for this assessment has ended.",
      "withdrawal_email_not_found": "We could not find that email in this study.",
      "paused_body": "The study is paused.",
      "notice_close_label": "Dismiss notice"
    },
    "flow_legacy": {
      "pages": [],
      "allow_back": true,
      "navigation_labels": {
        "back": "",
        "next": "",
        "finish": ""
      },
      "progress": "pages",
      "completion": {
        "title": "Gracias por responder",
        "message_html": "<p>Gracias por completar la evaluación.</p>",
        "image_url": "",
        "button_label": "",
        "allow_restart": true
      },
      "save_continue": {
        "enabled": false,
        "window": {
          "value": 7,
          "unit": "days"
        }
      },
      "metadata_profile": "basic"
    },
    "initial_legacy": {
      "contract": "INITIAL_SCREEN_V2",
      "title_html": "<h1>Participar en el estudio</h1>",
      "description_html": "",
      "footer_html": "",
      "background": "#ffffff",
      "text_color": "#243247",
      "max_width": 720,
      "authentication": {
        "type": "EMAIL",
        "label": "Email",
        "label_html": "",
        "label_visible": true,
        "placeholder": "nombre@ejemplo.com",
        "button": "Continuar",
        "verification": "NEVER",
        "confirm_after_first": true,
        "membership_enabled": false,
        "membership_label": "Ya participo en este estudio"
      },
      "withdrawal": {
        "label": "Abandonar estudio",
        "position": "BOTTOM",
        "align": "RIGHT",
        "text_color": "#536176",
        "background": "#ffffff",
        "border_color": "#d5dce5",
        "border_width": 1,
        "radius": 6,
        "font_size": 13,
        "modal_title": "Abandonar estudio",
        "modal_html": "<p>Elegí qué debe ocurrir con tus datos.</p>",
        "retain_label": "Dejar el estudio y permitir el uso de mis datos",
        "delete_label": "Dejar el estudio y solicitar eliminar mis datos",
        "confirm_label": "Confirmar abandono"
      },
      "templates": {
        "VERIFY_PARTICIPANT": {
          "subject": "",
          "html": ""
        },
        "ASSESSMENT_AVAILABLE": {
          "subject": "",
          "html": ""
        },
        "ASSESSMENT_REMINDER": {
          "subject": "",
          "html": ""
        }
      }
    }
  },
  "es": {
    "flow": {
      "pages": [],
      "allow_back": true,
      "navigation_labels": {
        "back": "Anterior",
        "next": "Siguiente",
        "finish": "Terminar"
      },
      "progress": "pages",
      "completion": {
        "title": "Gracias por responder",
        "message_html": "<p>Gracias por completar la evaluación.</p>",
        "image_url": "",
        "button_label": "",
        "allow_restart": true
      },
      "save_continue": {
        "enabled": false,
        "window": {
          "value": 7,
          "unit": "days"
        }
      },
      "metadata_profile": "basic",
      "messages": {
        "expired": "La sesión anterior venció. Empezá nuevamente desde la primera página.",
        "expired_saved": "Las respuestas parciales de la sesión anterior quedaron guardadas. Empezá nuevamente desde la primera página.",
        "processing": "Actualizando sesión…",
        "updated": "Sesión actualizada.",
        "completed": "Respuestas guardadas correctamente.",
        "invalid": "No se pudieron procesar tus respuestas. Volvé a intentarlo.",
        "delivery_error": "No se pudo confirmar el guardado de tus respuestas. Volvé a intentarlo con el mismo botón.",
        "discard_accept": "Aceptar",
        "discard_cancel": "Cancelar",
        "discard_label": "Descartar respuesta",
        "discard_confirm": "¿Descartar esta respuesta? Se eliminarán la sesión y las respuestas de esta evaluación para comenzar desde cero.",
        "discarded": "Respuesta descartada. Podés empezar desde el comienzo.",
        "progress": "Página [ACTUAL] de [TOTAL]",
        "progress_accessible": "Progreso por páginas"
      }
    },
    "flow_messages": {
      "expired": "La sesión anterior venció. Empezá nuevamente desde la primera página.",
      "expired_saved": "Las respuestas parciales de la sesión anterior quedaron guardadas. Empezá nuevamente desde la primera página.",
      "processing": "Actualizando sesión…",
      "updated": "Sesión actualizada.",
      "completed": "Respuestas guardadas correctamente.",
      "invalid": "No se pudieron procesar tus respuestas. Volvé a intentarlo.",
      "delivery_error": "No se pudo confirmar el guardado de tus respuestas. Volvé a intentarlo con el mismo botón.",
      "discard_accept": "Aceptar",
      "discard_cancel": "Cancelar",
      "discard_label": "Descartar respuesta",
      "discard_confirm": "¿Descartar esta respuesta? Se eliminarán la sesión y las respuestas de esta evaluación para comenzar desde cero.",
      "discarded": "Respuesta descartada. Podés empezar desde el comienzo.",
      "progress": "Página [ACTUAL] de [TOTAL]",
      "progress_accessible": "Progreso por páginas"
    },
    "initial": {
      "contract": "INITIAL_SCREEN_V2",
      "title_html": "<h1>Participar en el estudio</h1>",
      "description_html": "",
      "footer_html": "",
      "background": "#ffffff",
      "text_color": "#243247",
      "max_width": 720,
      "authentication": {
        "type": "EMAIL",
        "label": "Email",
        "label_html": "",
        "label_visible": true,
        "placeholder": "nombre@ejemplo.com",
        "button": "Continuar",
        "verification": "NEVER",
        "confirm_after_first": true,
        "membership_enabled": false,
        "membership_label": "Ya participo en este estudio"
      },
      "withdrawal": {
        "label": "Abandonar estudio",
        "position": "BOTTOM",
        "align": "RIGHT",
        "text_color": "#536176",
        "background": "#ffffff",
        "border_color": "#d5dce5",
        "border_width": 1,
        "radius": 6,
        "font_size": 13,
        "modal_title": "Abandonar estudio",
        "modal_html": "<p>Elegí qué debe ocurrir con tus datos.</p>",
        "retain_label": "Dejar el estudio y permitir el uso de mis datos",
        "delete_label": "Dejar el estudio y solicitar eliminar mis datos",
        "confirm_label": "Confirmar abandono",
        "modal_title_html": "",
        "typed_confirmation": true,
        "retain_background": "#edf3fa",
        "retain_text_color": "#243247",
        "delete_background": "#fff1f1",
        "delete_text_color": "#8a2424",
        "confirmation": {
          "title_html": "<h2>Confirmar decisión</h2>",
          "message_html": "<p>¿Está seguro de que desea [opcion]?</p>",
          "instruction_html": "<p>Para confirmar, complete el siguiente texto:</p>",
          "body_html": "<p>¿Está seguro de que desea [OPCION]? Para confirmar, escriba <strong>[CONFIRMACION]</strong>.</p>",
          "input_label": "Campo de confirmación",
          "input_placeholder": "Escribí el texto indicado en mayúsculas",
          "cancel_label": "Cancelar",
          "background": "#ffffff",
          "text_color": "#243247",
          "max_width": 720,
          "buttons_layout": "ROW"
        },
        "back_label": "Volver",
        "completion_html": "<h2>Tu participación terminó</h2><p>Registramos tu decisión: <strong>[OPCION]</strong>. Gracias por participar.</p>"
      },
      "templates": {
        "VERIFY_PARTICIPANT": {
          "subject": "Confirmá tu email",
          "html": "<h1>Confirmá tu email</h1><p>[study_name]</p><p>Confirmá tu dirección para recibir los próximos avisos.</p><p>[assessment_name]</p><p>Disponible: [available_time]</p><p>Vencimiento: [due_time]</p><p><a href=\"[direct_link]\">Continuar</a></p><hr><p><a href=\"[withdrawal_link]\">Abandonar estudio</a></p>"
        },
        "ASSESSMENT_AVAILABLE": {
          "subject": "Evaluación disponible",
          "html": "<h1>Evaluación disponible</h1><p>[study_name]</p><p>Tu próxima evaluación está disponible.</p><p>[assessment_name]</p><p>Disponible: [available_time]</p><p>Vencimiento: [due_time]</p><p><a href=\"[direct_link]\">Continuar</a></p><hr><p><a href=\"[withdrawal_link]\">Abandonar estudio</a></p>"
        },
        "ASSESSMENT_REMINDER": {
          "subject": "Recordatorio de evaluación",
          "html": "<h1>Recordatorio de evaluación</h1><p>[study_name]</p><p>Todavía podés completar tu evaluación.</p><p>[assessment_name]</p><p>Disponible: [available_time]</p><p>Vencimiento: [due_time]</p><p><a href=\"[direct_link]\">Continuar</a></p><hr><p><a href=\"[withdrawal_link]\">Abandonar estudio</a></p>"
        }
      }
    },
    "confirmation": {
      "title_html": "<h2>Confirmar decisión</h2>",
      "message_html": "<p>¿Está seguro de que desea [opcion]?</p>",
      "instruction_html": "<p>Para confirmar, complete el siguiente texto:</p>",
      "body_html": "<p>¿Está seguro de que desea [OPCION]? Para confirmar, escriba <strong>[CONFIRMACION]</strong>.</p>",
      "input_label": "Campo de confirmación",
      "input_placeholder": "Escribí el texto indicado en mayúsculas",
      "cancel_label": "Cancelar",
      "background": "#ffffff",
      "text_color": "#243247",
      "max_width": 720,
      "buttons_layout": "ROW"
    },
    "emails": {
      "VERIFY_PARTICIPANT": {
        "subject": "Confirmá tu email",
        "html": "<h1>Confirmá tu email</h1><p>[study_name]</p><p>Confirmá tu dirección para recibir los próximos avisos.</p><p>[assessment_name]</p><p>Disponible: [available_time]</p><p>Vencimiento: [due_time]</p><p><a href=\"[direct_link]\">Continuar</a></p><hr><p><a href=\"[withdrawal_link]\">Abandonar estudio</a></p>"
      },
      "ASSESSMENT_AVAILABLE": {
        "subject": "Evaluación disponible",
        "html": "<h1>Evaluación disponible</h1><p>[study_name]</p><p>Tu próxima evaluación está disponible.</p><p>[assessment_name]</p><p>Disponible: [available_time]</p><p>Vencimiento: [due_time]</p><p><a href=\"[direct_link]\">Continuar</a></p><hr><p><a href=\"[withdrawal_link]\">Abandonar estudio</a></p>"
      },
      "ASSESSMENT_REMINDER": {
        "subject": "Recordatorio de evaluación",
        "html": "<h1>Recordatorio de evaluación</h1><p>[study_name]</p><p>Todavía podés completar tu evaluación.</p><p>[assessment_name]</p><p>Disponible: [available_time]</p><p>Vencimiento: [due_time]</p><p><a href=\"[direct_link]\">Continuar</a></p><hr><p><a href=\"[withdrawal_link]\">Abandonar estudio</a></p>"
      }
    },
    "authoring": {
      "title": "Nueva evaluación",
      "question": "Escribí tu pregunta",
      "instruction": "Escribí las instrucciones de esta evaluación.",
      "option": [
        "Opción 1",
        "Opción 2"
      ],
      "likert": [
        "Muy mal",
        "Mal",
        "Neutral",
        "Bien",
        "Muy bien"
      ],
      "rows": [
        "Item 1",
        "Item 2"
      ],
      "row": "Nuevo item",
      "labels": {
        "text": "Texto",
        "textarea": "Texto largo",
        "email": "Email",
        "number": "Número",
        "tel": "Teléfono",
        "date": "Fecha",
        "url": "URL",
        "single_choice": "Opción única",
        "multiple_choice": "Opción múltiple",
        "select": "Lista desplegable",
        "likert": "Likert",
        "vas": "VAS",
        "matrix": "Matriz Likert",
        "instruction": "Texto / instrucciones",
        "image": "Imagen",
        "group": "Grupo / sección",
        "randomization": "Randomización",
        "page_break": "Salto de página"
      },
      "study_title": "Título del estudio",
      "text": "Texto",
      "new_option": "Nueva opción",
      "new_label": "Nueva etiqueta",
      "new_block": "Nuevo bloque",
      "block_selection": "Selección de bloques",
      "assessment_selection": "Selección de una evaluación",
      "study_label": "Estudio",
      "group": "Grupo",
      "assessment": "Evaluación",
      "template_names": {
        "VERIFY_PARTICIPANT": "Validación del email",
        "ASSESSMENT_AVAILABLE": "Evaluación disponible",
        "ASSESSMENT_REMINDER": "Recordatorio"
      }
    },
    "question_messages": {
      "required_message": "Respondé esta pregunta para continuar.",
      "length_message": "La respuesta debe tener entre [MINIMO] - [MAXIMO] caracteres.",
      "number_range_message": "La respuesta debe estar entre [MINIMO] - [MAXIMO].",
      "selection_min_message": "Elegí al menos [MINIMO] opciones.",
      "selection_max_message": "Elegí como máximo [MAXIMO] opciones.",
      "invalid_value_message": "Elegí una opción disponible.",
      "unanswered_value_message": "Sin responder",
      "matrix_heading": "Ítem",
      "empty_image_message": "Imagen no configurada",
      "date_placeholder": "AAAA-MM-DD",
      "date_open_label": "Abrir calendario",
      "date_close_label": "Cerrar calendario",
      "date_previous_label": "Mes anterior",
      "date_next_label": "Mes siguiente",
      "date_month_names": "Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre",
      "date_weekday_names": "Domingo|Lunes|Martes|Miércoles|Jueves|Viernes|Sábado"
    },
    "validation": {
      "text": "Ingresá texto válido.",
      "email": "Ingresá un email válido.",
      "url": "Ingresá una URL completa válida.",
      "letters": "Usá sólo letras y espacios.",
      "number": "Ingresá un número dentro del rango configurado.",
      "integer": "Ingresá un número entero.",
      "date": "Ingresá una fecha válida.",
      "vas": "Elegí un valor válido en la escala.",
      "multiple_choice": "Elegí opciones disponibles sin repetir."
    },
    "question_defaults": {
      "text": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "free",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "textarea": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "free",
        "display_mode": "multiline",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0,
        "rows": 4
      },
      "email": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "email",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "number": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "integer_only": false,
        "placeholder": "",
        "min": null,
        "max": null,
        "step": 1
      },
      "tel": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "tel",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "date": {
        "label_html": null,
        "helper_html": null,
        "footer_html": ""
      },
      "url": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "content_type": "url",
        "display_mode": "single",
        "placeholder": "",
        "max_length": 2000,
        "min_length": 0
      },
      "single_choice": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "columns": 1
      },
      "multiple_choice": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "columns": 1,
        "min_selected": 0,
        "max_selected": null
      },
      "select": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "placeholder": "Seleccioná una opción"
      },
      "likert": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "min": 1,
        "preset": "custom",
        "reversed": false,
        "gradient": "none",
        "gradient_start": "#f44336",
        "gradient_end": "#4caf50"
      },
      "vas": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "gradient": "none",
        "gradient_start": "#f44336",
        "gradient_end": "#4caf50",
        "labels": [
          "Nada",
          "Mucho"
        ],
        "label_font_size": 16,
        "label_scale": 100,
        "value_font_size": 36,
        "bold_labels": true,
        "label_boxes": false,
        "label_alignment": 100,
        "value_position": "below"
      },
      "matrix": {
        "label_html": null,
        "helper_html": null,
        "footer_html": "",
        "matrix_kind": "likert",
        "matrix_rows": [],
        "presentation": "horizontal",
        "min": 1,
        "preset": "custom",
        "reversed": false,
        "gradient": "none",
        "gradient_start": "#f44336",
        "gradient_end": "#4caf50"
      }
    },
    "vas_endpoints": [
      "Nada",
      "Mucho"
    ],
    "study": {
      "completion_title": "Gracias por participar",
      "completion_message": "Tus respuestas quedaron guardadas."
    },
    "public_messages": {
      "email_not_found": "No encontramos ese email en este estudio. Revisá la dirección o desmarcá «[PERTENENCIA]» para inscribirte.",
      "email_registered": "Ese email ya está registrado.",
      "email_validation_requested": "Si corresponde, te enviamos un enlace para validar tu email.",
      "email_first_link": "Te enviamos por email el acceso a la evaluación.",
      "email_validation_sent": "Te enviamos un enlace para validar tu email.",
      "email_verified": "Email validado.",
      "email_future_notices": "Recibirás por email avisos de las próximas evaluaciones.",
      "email_required": "Ingresá tu email.",
      "email_invalid": "Ingresá un email válido.",
      "review_title": "Confirmá tu email",
      "review_body": "Tu primera evaluación quedó guardada. Volvé a escribir tu dirección. Si hubo un error de tipeo, podés corregirla sin perder tus respuestas.",
      "review_label": "Email",
      "review_button": "Confirmar dirección",
      "review_link_button": "Enviar enlace de validación",
      "review_pending": "Solicitamos el envío del email de validación a [EMAIL]. Abrilo para confirmar la dirección. También podés corregirla y solicitar otro enlace.",
      "review_sent": "Enviamos el enlace de validación. Abrilo para confirmar tu dirección y acceder a las próximas evaluaciones.",
      "review_done": "Dirección confirmada.",
      "change_title": "Confirmar cambio de correo",
      "change_body": "El email es diferente al introducido anteriormente. ¿Estás seguro de que querés actualizar el correo?",
      "change_cancel": "Cancelar",
      "change_accept": "Actualizar correo",
      "withdrawal_invalid": "Escribí exactamente el nombre indicado en mayúsculas.",
      "withdrawal_input_label": "Campo de confirmación",
      "withdrawal_no_script": "Active JavaScript para usar la confirmación escrita de abandono.",
      "wait_title": "No hay evaluaciones disponibles.",
      "next_time": "Próxima disponibilidad:",
      "finished_body": "La participación finalizó.",
      "terminated_body": "La participación finalizó por una condición del estudio.",
      "finalized_body": "El estudio finalizó.",
      "conflict": "La evaluación cambió en otra pestaña. Revisá el estado actualizado.",
      "rate_limit": "Esperá un momento antes de volver a intentar.",
      "change_unconfirmed": "El email es diferente al introducido anteriormente. Confirmá que querés actualizarlo.",
      "unique_response": "Ya se registró una respuesta desde esta IP para este estudio.",
      "email_collision": "Ese email ya pertenece a otra participación. No se mezclaron tus respuestas; revisá la dirección o usá otra.",
      "invalid_fields": "Revisá los datos ingresados antes de continuar.",
      "operation_failed": "No pudimos guardar o cargar la evaluación. Intentá nuevamente.",
      "unavailable_title": "Evaluación no disponible",
      "content_failed": "No pudimos cargar el contenido publicado.",
      "document_title": "Evaluación",
      "assessment_expired": "El período de esta evaluación terminó.",
      "withdrawal_email_not_found": "No encontramos ese email en este estudio.",
      "paused_body": "El estudio está pausado.",
      "notice_close_label": "Cerrar aviso"
    },
    "flow_legacy": {
      "pages": [],
      "allow_back": true,
      "navigation_labels": {
        "back": "",
        "next": "",
        "finish": ""
      },
      "progress": "pages",
      "completion": {
        "title": "Gracias por responder",
        "message_html": "<p>Gracias por completar la evaluación.</p>",
        "image_url": "",
        "button_label": "",
        "allow_restart": true
      },
      "save_continue": {
        "enabled": false,
        "window": {
          "value": 7,
          "unit": "days"
        }
      },
      "metadata_profile": "basic"
    },
    "initial_legacy": {
      "contract": "INITIAL_SCREEN_V2",
      "title_html": "<h1>Participar en el estudio</h1>",
      "description_html": "",
      "footer_html": "",
      "background": "#ffffff",
      "text_color": "#243247",
      "max_width": 720,
      "authentication": {
        "type": "EMAIL",
        "label": "Email",
        "label_html": "",
        "label_visible": true,
        "placeholder": "nombre@ejemplo.com",
        "button": "Continuar",
        "verification": "NEVER",
        "confirm_after_first": true,
        "membership_enabled": false,
        "membership_label": "Ya participo en este estudio"
      },
      "withdrawal": {
        "label": "Abandonar estudio",
        "position": "BOTTOM",
        "align": "RIGHT",
        "text_color": "#536176",
        "background": "#ffffff",
        "border_color": "#d5dce5",
        "border_width": 1,
        "radius": 6,
        "font_size": 13,
        "modal_title": "Abandonar estudio",
        "modal_html": "<p>Elegí qué debe ocurrir con tus datos.</p>",
        "retain_label": "Dejar el estudio y permitir el uso de mis datos",
        "delete_label": "Dejar el estudio y solicitar eliminar mis datos",
        "confirm_label": "Confirmar abandono"
      },
      "templates": {
        "VERIFY_PARTICIPANT": {
          "subject": "",
          "html": ""
        },
        "ASSESSMENT_AVAILABLE": {
          "subject": "",
          "html": ""
        },
        "ASSESSMENT_REMINDER": {
          "subject": "",
          "html": ""
        }
      }
    }
  }
};
