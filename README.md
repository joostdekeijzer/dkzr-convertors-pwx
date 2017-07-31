# dkzr-convertors-pwx
PWX file convertor library

Converts PWX files to GPX or TCX

## CLI Usage

    convert-pwx  [OPTION]... SRC [DEST]
    
    Options
    -d    dump content to SDOUT
    -h    show this help
    -t    convert to type "gpx" (default) or "tcx"

## Include in your PHP project

    $output_text = DKZR\Convertors\Pwx::toGpx( $input_text );

or

    $output_text = DKZR\Convertors\Pwx::toTcx( $input_text );