<?php
/*
 * wpkgExpress : A web-based frontend to wpkg
 * Copyright 2009 Brian White
 *
 * This file is part of wpkgExpress.
 *
 * wpkgExpress is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * wpkgExpress is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with wpkgExpress.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
?>
<?php
echo $form->create('Installer', $url);
echo "<fieldset><legend>Step $step of $steps - " . $this->pageTitle . "</legend>";
if (isset($criticalerror)) {
	echo "<ul class=\"criticalerrors\">";
	echo "<li>" . $criticalerror . "</li>";
	echo "</ul>";
}
echo "<div class=\"installerHeader\">Web Access</div>";
echo $form->input('user', array('label' => 'Username: ', 'autocomplete' => "off"));
echo $form->input('password', array('label' => 'Password: '));
echo "<div class=\"installerHeader\">XML Feed Access</div>";
echo $form->input('protectxml', array('label' => 'Protect XML output: ', 'options' => array(1 => 'Yes', 0 => 'No')));
echo $form->input('xmluser', array('label' => 'XML Username: ', 'autocomplete' => "off"));
echo $form->input('xmlpassword', array('label' => 'XML Password: ', 'type' => 'password'));
echo "<hr />";
echo $form->end($submit);
echo "</fieldset>";
?>