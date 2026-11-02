<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance;

/**
 * The conformance case list: op programs that must draw identically to Chrome.
 */
final class Cases
{
    use Cases\Rects;
    use Cases\Paths;
    use Cases\Curves;
    use Cases\Strokes;
    use Cases\Transforms;
    use Cases\Composites;
    use Cases\Media;

    /**
     * @return array<int,array{id:string,w:int,h:int,tier:string,tag:string,ops:array}>
     */
    public static function all(): array
    {
        $all = array_merge(
            self::rectCases(),
            self::pathCases(),
            self::curveCases(),
            self::strokeCases(),
            self::transformCases(),
            self::compositeCases(),
            self::mediaCases(),
        );

        $seen = [];
        foreach ($all as $case) {
            if (isset($seen[$case['id']])) {
                throw new \LogicException('duplicate conformance case id: ' . $case['id']);
            }
            $seen[$case['id']] = true;
        }

        return $all;
    }
}
