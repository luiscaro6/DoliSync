<?php
/**
 * Validador de documentos espanoles (DNI, NIE, CIF) y pasaporte.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_Spanish_Document_Validator {
	private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';
	private const CIF_CONTROL_LETTERS = 'JABCDEFGHI';

	/**
	 * Normaliza un documento: mayusculas y sin espacios/guiones.
	 */
	public function normalize( $document ) {
		$document = strtoupper( trim( sanitize_text_field( (string) $document ) ) );
		return preg_replace( '/[\s\-]/', '', $document );
	}

	/**
	 * Valida documento y devuelve detalle.
	 *
	 * @return array{valid:bool,type:string,message:string,normalized:string}
	 */
	public function validate( $document ) {
		$normalized = $this->normalize( $document );

		if ( '' === $normalized ) {
			return array(
				'valid'      => false,
				'type'       => 'unknown',
				'message'    => __( 'El documento esta vacio.', 'dolisync' ),
				'normalized' => $normalized,
			);
		}

		if ( preg_match( '/^\d{8}[A-Z]$/', $normalized ) ) {
			return $this->validate_dni( $normalized );
		}

		if ( preg_match( '/^[XYZ]\d{7}[A-Z]$/', $normalized ) ) {
			return $this->validate_nie( $normalized );
		}

		if ( preg_match( '/^[ABCDEFGHJKLMNPQRSUVW]\d{7}[0-9A-J]$/', $normalized ) ) {
			return $this->validate_cif( $normalized );
		}

		// Si no encaja en formatos espanoles, tratar como pasaporte.
		if ( preg_match( '/^[A-Z0-9]{6,20}$/', $normalized ) ) {
			return array(
				'valid'      => true,
				'type'       => 'passport',
				'message'    => '',
				'normalized' => $normalized,
			);
		}

		return array(
			'valid'      => false,
			'type'       => 'unknown',
			'message'    => __( 'Formato de documento no valido.', 'dolisync' ),
			'normalized' => $normalized,
		);
	}

	private function validate_dni( $dni ) {
		$number = (int) substr( $dni, 0, 8 );
		$letter = substr( $dni, -1 );
		$expected = self::DNI_LETTERS[ $number % 23 ];

		if ( $letter !== $expected ) {
			return array(
				'valid'      => false,
				'type'       => 'dni',
				'message'    => __( 'La letra del DNI no es correcta.', 'dolisync' ),
				'normalized' => $dni,
			);
		}

		return array(
			'valid'      => true,
			'type'       => 'dni',
			'message'    => '',
			'normalized' => $dni,
		);
	}

	private function validate_nie( $nie ) {
		$prefix_map = array(
			'X' => '0',
			'Y' => '1',
			'Z' => '2',
		);

		$prefix = substr( $nie, 0, 1 );
		$number = $prefix_map[ $prefix ] . substr( $nie, 1, 7 );
		$letter = substr( $nie, -1 );
		$expected = self::DNI_LETTERS[ ((int) $number) % 23 ];

		if ( $letter !== $expected ) {
			return array(
				'valid'      => false,
				'type'       => 'nie',
				'message'    => __( 'La letra del NIE no es correcta.', 'dolisync' ),
				'normalized' => $nie,
			);
		}

		return array(
			'valid'      => true,
			'type'       => 'nie',
			'message'    => '',
			'normalized' => $nie,
		);
	}

	private function validate_cif( $cif ) {
		$entity = substr( $cif, 0, 1 );
		$digits = substr( $cif, 1, 7 );
		$control = substr( $cif, -1 );

		$sum_even = 0;
		$sum_odd = 0;

		for ( $i = 0; $i < 7; $i++ ) {
			$digit = (int) $digits[ $i ];
			$position = $i + 1;

			if ( 0 === $position % 2 ) {
				$sum_even += $digit;
			} else {
				$tmp = $digit * 2;
				$sum_odd += intdiv( $tmp, 10 ) + ( $tmp % 10 );
			}
		}

		$total = $sum_even + $sum_odd;
		$control_digit = ( 10 - ( $total % 10 ) ) % 10;
		$control_letter = self::CIF_CONTROL_LETTERS[ $control_digit ];

		$must_be_letter = in_array( $entity, array( 'P', 'Q', 'R', 'S', 'N', 'W' ), true );
		$must_be_digit = in_array( $entity, array( 'A', 'B', 'E', 'H' ), true );

		$valid = false;
		if ( $must_be_letter ) {
			$valid = ( $control === $control_letter );
		} elseif ( $must_be_digit ) {
			$valid = ( $control === (string) $control_digit );
		} else {
			$valid = ( $control === (string) $control_digit || $control === $control_letter );
		}

		if ( ! $valid ) {
			return array(
				'valid'      => false,
				'type'       => 'cif',
				'message'    => __( 'El caracter de control del CIF no es correcto.', 'dolisync' ),
				'normalized' => $cif,
			);
		}

		return array(
			'valid'      => true,
			'type'       => 'cif',
			'message'    => '',
			'normalized' => $cif,
		);
	}
}
