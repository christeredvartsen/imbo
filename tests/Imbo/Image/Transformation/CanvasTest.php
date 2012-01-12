<?php
/**
 * Imbo
 *
 * Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * * The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package Imbo
 * @subpackage Unittests
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/imbo
 */

namespace Imbo\Image\Transformation;

/**
 * @package Imbo
 * @subpackage Unittests
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/imbo
 */
class CanvasTest extends TransformationTests {
    protected function getTransformation() {
        return new Canvas(100, 100, 'free', 10, 10, '000');
    }

    public function testApplyToImage() {
        $mode = 'free';
        $width = 100;
        $height = 200;
        $x = 10;
        $y = 20;
        $bg = '000';
        $blob = file_get_contents(__DIR__ . '/../../_files/image.png');

        $image = $this->getMock('Imbo\Image\ImageInterface');
        $image->expects($this->once())->method('getBlob')->will($this->returnValue($blob));
        $image->expects($this->once())->method('setBlob')->with($this->isType('string'))->will($this->returnValue($image));
        $image->expects($this->once())->method('setWidth')->with($width)->will($this->returnValue($image));
        $image->expects($this->once())->method('setHeight')->with($height)->will($this->returnValue($image));
        $image->expects($this->once())->method('getExtension')->will($this->returnValue('png'));

        $imagineImage = $this->getMock('Imagine\Image\ImageInterface');

        $canvas = $this->getMock('Imagine\Image\ImageInterface');
        $canvas->expects($this->once())->method('paste')->with($imagineImage);
        $canvas->expects($this->once())->method('get')->with('png')->will($this->returnValue($blob));

        $imagine = $this->getMock('Imagine\Image\ImagineInterface');
        $imagine->expects($this->once())->method('create')->will($this->returnValue($canvas));
        $imagine->expects($this->once())->method('load')->with($blob)->will($this->returnValue($imagineImage));

        $transformation = new Canvas($width, $height, $mode, $x, $y, $bg);
        $transformation->setImagine($imagine);
        $transformation->applyToImage($image);
    }
}