<?php

namespace Cheesegrits\FilamentGoogleMaps\Fields;

use Cheesegrits\FilamentGoogleMaps\Helpers\FieldHelper;
use Cheesegrits\FilamentGoogleMaps\Helpers\GeocodeHelper;
use Closure;
use Exception;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Concerns\HasExtraInputAttributes;
use Filament\Forms\Components\Contracts\CanBeLengthConstrained;
use Filament\Forms\Components\Contracts\CanConcealComponents;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Concerns;
use JsonException;

class Geocomplete extends Field implements CanBeLengthConstrained
{
	use Concerns\CanBeLengthConstrained;
	use Concerns\HasAffixes;
	use Concerns\HasExtraInputAttributes;
	use Concerns\HasInputMode;
	use Concerns\HasPlaceholder;

	protected string $view = 'filament-google-maps::fields.filament-google-geocomplete';

	protected int $precision = 8;

	protected Closure|string|null $filterName = null;

	protected Closure|string|null $placeField = null;

	protected Closure|bool $isLocation = false;

	protected Closure|array $reverseGeocode = [];

	protected Closure|array $types = [];


	/**
	 * DO NOT USE!  Only used by the Radius Filter, to set the state path for the filter form data.
	 *
	 * @param Closure|string $name
	 *
	 * @return $this
	 */
	public function filterName(Closure|string $name): static
	{
		$this->filterName = $name;

		return $this;
	}

	public function getFilterName(): string|null
	{
		$name = $this->evaluate($this->filterName);

		if ($name)
		{
			return 'tableFilters.' . $name;
		}

		return null;
	}

	/**
	 * Optionally set this to true, if you want the geocomplete to update lat/lng fields on your form
	 *
	 * @param Closure|string $name
	 *
	 * @return $this
	 */
	public function isLocation(Closure|bool $isLocation = true): static
	{
		$this->isLocation = $isLocation;

		return $this;
	}

	public function getIsLocation(): string|null
	{
		return $this->evaluate($this->isLocation);
	}


	/**
	 * Optionally provide an array of field names and format strings as key and value, if you would like the map to reverse geocode
	 * address components to individual fields on your form.  See documentation for full explanation of format strings.
	 *
	 * ->reverseGeocode(['street' => '%n %s', 'city' => '%L', 'state' => %A1', 'zip' => '%z'])
	 *
	 * Street Number: %n
	 * Street Name: %S
	 * City (Locality): %L
	 * City District (Sub-Locality): %D
	 * Zipcode (Postal Code): %z
	 * Admin Level Name: %A1, %A2, %A3, %A4, %A5
	 * Admin Level Code: %a1, %a2, %a3, %a4, %a5
	 * Country: %C
	 * Country Code: %c
	 *
	 * @param Closure|array $reverseGeocode
	 *
	 * @return $this
	 */
	public function reverseGeocode(Closure|array $reverseGeocode): static
	{
		$this->reverseGeocode = $reverseGeocode;

		return $this;
	}

	public function getReverseGeocode(): array
	{
		$fields     = $this->evaluate($this->reverseGeocode);
		$statePaths = [];

		foreach ($fields as $field => $format)
		{
			$fieldId = FieldHelper::getFieldId($field, $this);

			if ($fieldId)
			{
				$statePaths[$fieldId] = $format;
			}
		}

		return $statePaths;
	}

	/**
	 * And array of place types, see "Constrain Place Types" section of Google Places API doc:
	 *
	 * https://developers.google.com/maps/documentation/javascript/place-autocomplete
	 *
	 * In particular, note the restrictions on number of types (5), and not mixing from tables 1 or 2 with
	 * table 3.
	 *
	 * Defaults to 'geocode'
	 *
	 * @param Closure|array $types
	 *
	 * @return $this
	 */
	public function types(Closure|array $types): static
	{
		$this->types = $types;

		return $this;
	}

	public function getTypes(): array
	{
		$types = $this->evaluate($this->types);

		if (count($types) === 0)
		{
			$types = ['geocode'];
		}

		return $types;
	}

	public function placeField(Closure|string $placeField): static
	{
		$this->placeField = $placeField;

		return $this;
	}

	public function getPlaceField(): string|null
	{
		return $this->evaluate($this->placeField) ?? 'formatted_address';
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this->afterStateHydrated(static function (Geocomplete $component, $state) {
			if ($component->getIsLocation())
			{
				$state = static::getLocationState($state);

				if (!FieldHelper::blankLocation($state))
				{
					$state = GeocodeHelper::reverseGeocode($state);

				}
				else
				{
					$state = '';
				}

				$component->state((string) $state);
			}
		});

		$this->beforeStateDehydrated(static function (string|array|null $state, $record, $model, Geocomplete $component) {
			if (!blank($state))
			{
				if ($component->getIsLocation())
				{
					$latLang = GeocodeHelper::geocode($state);
					$record->setLocationAttribute($latLang);
				}
			}
		});
	}

	/**
	 * Create json configuration string
	 * @return string
	 */
	public function getMapConfig(): string
	{
		$gmaps = 'https://maps.googleapis.com/maps/api/js'
			. '?key=' . config('filament-google-maps.key')
			. '&libraries=places'
			. '&v=weekly'
			. '&language=' . app()->getLocale();

		$config = json_encode([
			'filterName'           => $this->getFilterName(),
			'statePath'            => $this->getStatePath(),
			'location'             => $this->getIsLocation(),
			'reverseGeocodeFields' => $this->getReverseGeocode(),
			'types'                => $this->getTypes(),
			'placeField'           => $this->getPlaceField(),
			'gmaps'                => $gmaps,
		]);

		//ray($config);

		return $config;
	}

	public static function getLocationState($state)
	{
		if (is_array($state))
		{
			return $state;
		}
		else
		{
			try
			{
				return @json_decode($state, true, 512, JSON_THROW_ON_ERROR);
			}
			catch (Exception $e)
			{
				return [
					'lat' => 0,
					'lng' => 0
				];
			}
		}
	}

	public function hasJs(): bool
	{
		return true;
	}

	public function jsUrl(): string
	{
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../dist/mix-manifest.json'), true);

		return url($manifest['/cheesegrits/filament-google-maps/filament-google-geocomplete.js']);
	}

	public function hasCss(): bool
	{
		return false;
	}

	public function cssUrl(): string
	{
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../dist/mix-manifest.json'), true);

		return url($manifest['/cheesegrits/filament-google-maps/filament-google-geocomplete.css']);
	}

}