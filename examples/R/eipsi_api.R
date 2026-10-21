# EIPSI Forms — descargar datos desde R / RStudio.
# Primera vez: install.packages("httr2")
# 1. En WordPress: Configuración > API externa > Cómo conectar, copiá la dirección base.
# 2. Creá una clave con read:studies, read:data y acceso al estudio.
# 3. Pegá ambos valores entre las comillas de abajo.
#    Si los dejás vacíos, se usan las variables de entorno existentes o se te
#    solicitan en la consola al cargar este archivo con source(file.choose()).
# 4. Al final del archivo hay instrucciones para descargar y ver los resultados.
# Contrato EIPSI_STUDY_DATA_V2: una fila por participación y momento real (UTC).
# JSON/XLSX incluyen diccionario, estados y metadatos de proceso; CSV contiene data.
base <- ""
key <- ""

suppressPackageStartupMessages(library(httr2))
if (!nzchar(base)) base <- Sys.getenv('EIPSI_API_BASE')
if (!nzchar(key)) key <- Sys.getenv('EIPSI_API_KEY')
if (!nzchar(base) && interactive()) base <- readline("Pegá la dirección base de la API: ")
if (!nzchar(key) && interactive()) key <- readline("Pegá la clave API: ")
base <- sub('/$', '', trimws(base))
key <- trimws(key)
if (!nzchar(base) || !nzchar(key))
  stop("Completá base y key al inicio del archivo, o definí EIPSI_API_BASE y EIPSI_API_KEY.", call.=FALSE)
if (!grepl('^eipsi_[a-f0-9]{32}_[a-f0-9]{64}$', key))
  stop("La clave API no tiene el formato esperado. Copiá la clave completa, sin espacios.", call.=FALSE)
if (!grepl('^https://', base) && !grepl('^http://(localhost|127\\.0\\.0\\.1)(:|/)', base))
  stop("La dirección base debe usar HTTPS, o HTTP en localhost para pruebas locales.", call.=FALSE)
api <- function(path, query=list(), body=NULL, file=NULL) {
  # Query-style WordPress installations are supported by appending the path
  # before httr2 adds any other query parameters.
  request <- request(paste0(base,path)) |> req_auth_bearer_token(key) |>
    req_options(followlocation=FALSE) |> req_timeout(90) |>
    req_error(is_error=function(resp) FALSE)
  if (length(query)) request <- do.call(req_url_query,c(list(request),query))
  if (!is.null(body)) request <- req_body_json(request,body,auto_unbox=TRUE)
  response <- req_perform(request,path=file)
  if (resp_status(response)>=300) {
    # Do not print request, key, headers, or raw error body.
    stop(sprintf('EIPSI HTTP %d',resp_status(response)),call.=FALSE)
  }
  if (!is.null(file)) return(invisible(response))
  resp_body_json(response,simplifyVector=FALSE)
}
pages <- function(path, query=list()) {
  result <- list(); seen <- character()
  for (i in seq_len(10000)) {
    page <- api(path,query); result <- c(result,page$items)
    if (is.null(page$next_cursor)) return(result)
    stopifnot(!(page$next_cursor %in% seen))
    seen <- c(seen,page$next_cursor);query$cursor <- page$next_cursor
  }
  stop('Pagination bound exceeded')
}
# List columns preserve arrays and JSON nulls without flattening scientific values.
as_data_frame <- function(items) {
  if (!length(items)) return(data.frame())
  cols <- names(items[[1]])
  values <- lapply(cols,function(k) I(lapply(items,function(row) row[[k]])))
  names(values) <- cols
  as.data.frame(values,optional=TRUE)
}
run_example <- function(study, directory, format='json') {
  stopifnot(format %in% c('json','csv','xlsx'))
  stopifnot(dir.exists(directory))
  code <- URLencode(study,reserved=TRUE)
  api('/status'); studies <- pages('/studies',list(limit=50))
  rows <- pages(paste0('/studies/',code,'/data'),list(limit=100,contract='EIPSI_STUDY_DATA_V2'))
  frame <- as_data_frame(rows)
  dictionary <- api(paste0('/studies/',code,'/dictionary'),list(contract='EIPSI_STUDY_DATA_V2'))
  live_csv <- file.path(directory,'live.csv');stopifnot(!file.exists(live_csv))
  api(paste0('/studies/',code,'/data'),list(format='csv',contract='EIPSI_STUDY_DATA_V2'),file=live_csv)
  job <- api(paste0('/studies/',code,'/exports'),body=list(contract='EIPSI_STUDY_DATA_V2',format=format,include_partials=FALSE,annexes=c('data','dictionary','response_status','bindings','process_metadata','randomization')))
  deadline <- Sys.time()+300
  repeat {
    job <- api(paste0('/exports/',job$export_id))
    if (job$status=='READY') break
    if (job$status!='PREPARING' || Sys.time()>deadline) stop('Export unavailable or timed out')
    Sys.sleep(5)
  }
  destination <- file.path(directory,paste0('snapshot.',format));stopifnot(!file.exists(destination))
  api(paste0('/exports/',job$export_id,'/download'),file=destination)
  invisible(list(studies=studies,data=frame,dictionary=dictionary,export=job))
}
# Después de cargar este archivo, ejecutá en la consola:
# estudios <- pages('/studies', list(limit=50))
# str(estudios)  # Buscá el código del estudio que querés descargar.
# carpeta <- tempfile("eipsi-descarga-")
# dir.create(carpeta)
# resultado <- run_example('CODIGO_DEL_ESTUDIO', carpeta)
# View(resultado$data)
# str(resultado$dictionary)
# list.files(carpeta, full.names=TRUE)  # live.csv y snapshot.json
# El JSON contiene data, dictionary, response_status y process_metadata.
# Para Excel usá run_example('CODIGO_DEL_ESTUDIO', otra_carpeta_nueva, format='xlsx').
# Usá una carpeta nueva para cada descarga: no se sobrescriben archivos existentes.
